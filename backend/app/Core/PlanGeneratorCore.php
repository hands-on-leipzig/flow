<?php

namespace App\Core;

use App\Enums\ExploreMode;
use App\Enums\FirstProgram;
use App\Services\AfternoonBlockOrderService;
use App\Support\IntegratedExploreState;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use App\Support\UsesPlanParameter;
use Illuminate\Support\Facades\Log;

class PlanGeneratorCore
{
    private ActivityWriter $writer;

    private ChallengeShapedLead $lead;

    private ?ChallengeGenerator $challenge = null;

    private ?Future8Generator $future = null;

    private ?GameRoundCoordinator $coordinator = null;

    private ExploreGenerator $explore;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    private TimeCursor $eTime;

    use UsesPlanParameter;

    public function __construct(int $planId, PlanParameter $params)
    {
        $this->writer = new ActivityWriter($planId, $params);
        $this->params = $params;
        $this->integratedExplore = new IntegratedExploreState;
        $this->eTime = new TimeCursor(clone $params->get('g_date'));
    }

    public static function generate(int $planId): void
    {
        Log::info("PlanGeneratorCore: Start generation for plan {$planId}");

        $params = PlanParameter::load($planId);
        $instance = new self($planId, $params);

        try {
            $instance->generateByMode();
        } catch (\Throwable $e) {
            Log::error("Plan generation failed: {$e->getMessage()}", ['plan_id' => $planId]);
            throw $e;
        }

        // -----------------------------------------------------------------------------------
        // Add all free blocks.
        // Timing does not matter, because these are parallel to other activities.
        // -----------------------------------------------------------------------------------

        (new FreeBlockGenerator($instance->writer, $instance->params))->insertFreeActivities();

        // Re-materialize slot-based activities after full regeneration.
        // Full generation rebuilds all activity groups, so slot assignments
        // must be re-applied from slot_block_team to activity rows.
        app(\App\Services\SlotBlockPlanSyncService::class)->applyToPlan($planId);

        Log::info("PlanGeneratorCore: Finished generation for plan {$planId}");
    }

    private function generateByMode(): void
    {
        // Check for finale event (level 3) - special 2-day generation path
        if ($this->pp('g_finale')) {
            // Finale event - delegate to FinaleGenerator for complete 2-day generation
            $finale = new FinaleGenerator($this->writer, $this->params);
            $finale->generate();

            return;
        }

        // Normal events - use standard one-day generation
        $this->generateOneDayEvent();
    }

    /**
     * Generate a standard one-day event.
     * Called for normal events and Finale Day 2.
     *
     * Ceremony recipes (Challenge-shaped lead; Explore is a wrapper). Same call order as before.
     * Lead is Challenge when c_mode is on; Future 8+ when f8_mode is on and Challenge is off.
     * When both Challenge and Future are on: Policy A/B via GameRoundCoordinator, or Policy C
     * (g_separate_rooms) as parallel solo mains.
     *
     * Explore e_mode (generated):
     *
     *   0 NONE                 No Explore
     *   1 INTEGRATED_MORNING   Joint opening; Explore 1 judging; Explore 1 awards after RG1;
     *                          Challenge awards alone
     *   2 INTEGRATED_AFTERNOON Challenge opening; Explore 2 opening after RG1; Explore 2 judging;
     *                          joint awards
     *   3 DECOUPLED_MORNING    Full Challenge, then Explore 1 with own ceremonies
     *   4 DECOUPLED_AFTERNOON  Full Challenge, then Explore 2 with own ceremonies
     *   5 DECOUPLED_BOTH       Full Challenge, then Explore 1 and 2 with own ceremonies
     *   8 HYBRID_BOTH          Joint opening; Explore 1 with own awards; joint awards;
     *                          Explore 2 opening after that + judging (no Explore 2 awards)
     *
     * UI never writes 6 or 7 (morning+integrated → 1, afternoon+integrated → 2).
     * Enum has no cases for them. Integers 6 and 7 stay reserved and unimplemented:
     *
     *   6 would be hybrid morning (half of 8 vs 1): joint opening like 8; Explore 1 judging;
     *     Explore 1 awards independent of Challenge (not after RG1); Challenge awards alone.
     *   7 would be hybrid afternoon (half of 8 vs 2): Challenge opening; Explore 2 opening
     *     independent of Challenge (not after RG1); Explore 2 judging; joint awards.
     */
    public function generateOneDayEvent(): void
    {
        $planId = (int) $this->pp('g_plan');
        $presence = ProgramPresence::forPlan($planId, $this->params);
        $eMode = $presence->exploreMode();
        $onIds = $presence->challengeShapedOnIds();
        $leadId = $presence->leadProgramId();

        if ($leadId !== null) {
            $this->constructChallengeShaped($onIds);
            $this->runLeadRecipes($eMode);
            $this->insertEventEnd();

            return;
        }

        if ($eMode === ExploreMode::NONE->value) {
            Log::warning('PlanGeneratorCore: All programs disabled - generating empty plan');

            return;
        }

        $this->makeExplore();

        $this->recipeExploreOnly($eMode);
        $this->insertEventEnd();
    }

    /**
     * @param list<int> $onIds
     */
    private function constructChallengeShaped(array $onIds): void
    {
        $challengeOn = in_array(FirstProgram::CHALLENGE->value, $onIds, true);
        $futureOn = in_array(FirstProgram::FUTURE_8->value, $onIds, true);

        if ($challengeOn) {
            $this->challenge = new ChallengeGenerator(
                $this->writer,
                $this->params,
                $this->integratedExplore
            );
        }

        if ($futureOn) {
            $this->future = new Future8Generator(
                $this->writer,
                $this->params,
                $this->integratedExplore
            );
        }

        // Ceremony lead: Challenge when on, else Future (same as ProgramPresence::leadProgramId).
        $this->lead = $this->challenge ?? $this->future;

        if ($this->challenge !== null && $this->future !== null) {
            $this->coordinator = new GameRoundCoordinator(
                $this->challenge,
                $this->future,
                $this->params
            );
            $policyC = (bool) $this->ppLoaded('g_separate_rooms', false);
            Log::info('PlanGeneratorCore: dual Challenge-shaped morning', [
                'plan_id' => $this->pp('g_plan'),
                'g_separate_rooms' => $policyC,
                'g_future_first' => (bool) $this->ppLoaded('g_future_first'),
                'g_per_round' => (bool) $this->ppLoaded('g_per_round', true),
                'policy' => $policyC ? 'C' : ((bool) $this->ppLoaded('g_per_round', true) ? 'A' : 'B'),
            ]);
        }
    }

    private function runLeadRecipes(int $eMode): void
    {
        match ($eMode) {
            ExploreMode::NONE->value => $this->recipeLeadOnly(),
            ExploreMode::INTEGRATED_MORNING->value => $this->recipeIntegratedMorning(),
            ExploreMode::INTEGRATED_AFTERNOON->value => $this->recipeIntegratedAfternoon(),
            ExploreMode::HYBRID_BOTH->value => $this->recipeHybridBoth(),
            ExploreMode::DECOUPLED_MORNING->value,
            ExploreMode::DECOUPLED_AFTERNOON->value,
            ExploreMode::DECOUPLED_BOTH->value => $this->recipeDecoupled($eMode),
            default => null,
        };
    }

    /** Challenge-shaped lead only: opening → main (judging ∥ games) → afternoon → awards. */
    private function recipeLeadOnly(): void
    {
        $this->openingsForPrograms(false);
        $this->runMain();
        $this->afternoon();
        $this->awardsForPrograms();
    }

    /**
     * Joint opening. Explore morning judging. After RG1, Explore awards (handoff). Challenge awards alone.
     */
    private function recipeIntegratedMorning(): void
    {
        $this->makeExplore();

        $this->openingsForPrograms(true);
        $this->explore->openingsAndBriefings(1);
        $this->explore->judgingAndDeliberations(1);

        $afterRG1Callback = function (TimeCursor $rTime) {
            $rg1End = $this->integratedExplore->rg1End ?? $rTime->current();
            $deliberationsEnd = $this->integratedExplore->deliberationsEnd;
            $earliestStart = $rg1End;
            if ($deliberationsEnd !== null && $deliberationsEnd > $earliestStart) {
                $earliestStart = $deliberationsEnd;
            }

            $this->eTime->set($earliestStart);
            $this->explore->awards(1);

            $exploreEnd = $this->eTime->current();
            $exploreEnd->modify('+'.((int) $this->pp('e_ready_awards', 0)).' minutes');
            $rTime->advanceToLater($exploreEnd);
            if ($this->challenge !== null) {
                $this->challenge->rTime()->advanceToLater($rTime->current());
            }
            // Policy C: Future runs solo from opening end — do not pull F8 into the Explore hole.
            if ($this->future !== null && ! $this->isPolicyC()) {
                $this->future->rTime()->advanceToLater($rTime->current());
            }
        };

        $this->runMain(true, $afterRG1Callback);

        $this->afternoon();
        // Explore already awarded; Challenge-shaped programs share joint awards when both on.
        $this->awardsForPrograms();
    }

    /**
     * Challenge opening. After RG1, Explore afternoon opening (handoff). Joint awards.
     */
    private function recipeIntegratedAfternoon(): void
    {
        $this->makeExplore();

        $this->openingsForPrograms(false);
        $this->runMain(true);
        $start = $this->integratedExplore->startTime;
        if ($start !== null) {
            $this->eTime->set($start);
            $this->eTime->addMinutes((int) $this->pp('e_ready_opening', 0));
            $this->explore->openingsAndBriefings(2);
        }
        $this->explore->judgingAndDeliberations(2);
        $this->afternoon();
        $this->awardsForPrograms(true);
    }

    /**
     * Joint opening. Explore morning full day (own awards). Joint Challenge awards. Explore afternoon opening after RG1, then judging (no Explore afternoon awards).
     */
    private function recipeHybridBoth(): void
    {
        $this->makeExplore();

        $this->openingsForPrograms(true);
        $this->runMain();

        $this->explore->openingsAndBriefings(1);
        $this->explore->judgingAndDeliberations(1);
        $this->explore->awards(1);

        $this->afternoon();
        $this->awardsForPrograms(true);

        $start = $this->integratedExplore->startTime;
        if ($start !== null) {
            $this->eTime->set($start);
            $this->explore->openingsAndBriefings(2);
        }
        $this->explore->judgingAndDeliberations(2);
    }

    /** Full Challenge day, then Explore group(s) fully decoupled (own opening, judging, awards). */
    private function recipeDecoupled(int $eMode): void
    {
        $this->makeExplore();

        $this->openingsForPrograms(false);
        $this->runMain();
        $this->afternoon();
        $this->awardsForPrograms();

        if ($eMode === ExploreMode::DECOUPLED_MORNING->value || $eMode === ExploreMode::DECOUPLED_BOTH->value) {
            $this->explore->openingsAndBriefings(1);
            $this->explore->judgingAndDeliberations(1);
            $this->explore->awards(1);
        }

        if ($eMode === ExploreMode::DECOUPLED_AFTERNOON->value || $eMode === ExploreMode::DECOUPLED_BOTH->value) {
            $this->explore->openingsAndBriefings(2);
            $this->explore->judgingAndDeliberations(2);
            $this->explore->awards(2);
        }
    }

    /**
     * Ceremony lead writes opening (+ its briefings). When Future is secondary, add Future briefings
     * and sync its clocks to the lead (coordinator also syncs before morning).
     * Policy C always uses a joint opening even without Explore (`c+f8_opening`).
     */
    private function openingsForPrograms(bool $jointOpening): void
    {
        $this->lead->openingsAndBriefings($jointOpening || $this->isPolicyC(), $this->jointCeremonyPrefix());

        if ($this->coordinator !== null && $this->future !== null && $this->challenge !== null) {
            // Lead is Challenge: Future briefings only (opening already joint/Challenge).
            $this->future->briefings($this->challenge->cTime()->current());
            $this->future->syncClocksFrom($this->challenge);
        }
    }

    private function isPolicyC(): bool
    {
        return $this->challenge !== null
            && $this->future !== null
            && (bool) $this->pp('g_separate_rooms', false);
    }

    /** Joint C+F8 ceremonies use `c+f8_*` when Explore is off; Explore-integrated joint stays `g_*`. */
    private function jointCeremonyPrefix(): string
    {
        if ($this->challenge !== null
            && $this->future !== null
            && (int) $this->pp('e_mode', 0) === ExploreMode::NONE->value) {
            return 'c+f8';
        }

        return 'g';
    }

    private function runMain(bool $explore = false, ?callable $afterRG1Callback = null): void
    {
        if ($this->isPolicyC()) {
            $this->runPolicyCMain($explore, $afterRG1Callback);

            return;
        }

        if ($this->coordinator !== null) {
            $this->coordinator->main($explore, $afterRG1Callback);

            return;
        }

        $this->lead->main($explore, $afterRG1Callback);
    }

    /**
     * Policy C: parallel solo mornings from the same opening-end anchor.
     * Challenge runs first (owns Explore hooks); Future is re-anchored then runs standalone.
     */
    private function runPolicyCMain(bool $explore = false, ?callable $afterRG1Callback = null): void
    {
        $openingC = clone $this->challenge->cTime()->current();
        $openingJ = clone $this->challenge->jTime()->current();
        $openingR = clone $this->challenge->rTime()->current();

        Log::info('PlanGeneratorCore: Policy C parallel solo morning', [
            'plan_id' => $this->pp('g_plan'),
            'opening' => $openingR->format('H:i'),
        ]);

        $this->challenge->main($explore, $afterRG1Callback);

        $this->future->cTime()->set(clone $openingC);
        $this->future->jTime()->set(clone $openingJ);
        $this->future->rTime()->set(clone $openingR);
        $this->future->setCoordinateExplore(false);
        $this->future->main(false, null);
    }

    /**
     * Ceremony awards. When Challenge and Future are both on, always joint from the later end
     * (`c+f8_awards` without Explore, `g_awards` when Explore is on).
     *
     * @param  bool  $jointWithExplore  Explore-integrated recipes that already used g_awards with Explore.
     */
    private function awardsForPrograms(bool $jointWithExplore = false): void
    {
        if ($this->challenge !== null && $this->future !== null) {
            $this->syncSharedCeremonyClock();
            $this->lead->awards(true, $this->jointCeremonyPrefix());

            return;
        }

        $this->lead->awards($jointWithExplore, $this->jointCeremonyPrefix());
    }

    /**
     * Zero-duration marker after the last awards — wall-clock end of the event (code g_end).
     */
    private function insertEventEnd(): void
    {
        $end = null;

        if ($this->challenge !== null) {
            $end = $this->challenge->cTime()->current();
        }
        if ($this->future !== null) {
            $t = $this->future->cTime()->current();
            if ($end === null || $t > $end) {
                $end = $t;
            }
        }
        if (isset($this->explore)) {
            $t = $this->eTime->current();
            if ($end === null || $t > $end) {
                $end = $t;
            }
        }

        if ($end === null) {
            return;
        }

        $cursor = new TimeCursor(clone $end);
        $this->writer->withGroup('g_end', function () use ($cursor) {
            $this->writer->insertActivity('g_end', $cursor, 0);
        });
    }

    private function makeExplore(): void
    {
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );
    }

    /** Explore only: decoupled morning and/or afternoon. Other e_mode values construct Explore and generate nothing. */
    private function recipeExploreOnly(int $eMode): void
    {
        if ($eMode === ExploreMode::DECOUPLED_MORNING->value || $eMode === ExploreMode::DECOUPLED_BOTH->value) {
            $this->explore->openingsAndBriefings(1);
            $this->explore->judgingAndDeliberations(1);
            $this->explore->awards(1);
        }

        if ($eMode === ExploreMode::DECOUPLED_AFTERNOON->value || $eMode === ExploreMode::DECOUPLED_BOTH->value) {
            $this->explore->openingsAndBriefings(2);
            $this->explore->judgingAndDeliberations(2);
            $this->explore->awards(2);
        }
    }

    /**
     * Walk the Nachmittag list. A/B: one shared stage clock. Policy C: each program alone.
     */
    private function afternoon(): void
    {
        Log::info('PlanGeneratorCore::afternoon', [
            'plan_id' => $this->pp('g_plan'),
            'policy_c' => $this->isPolicyC(),
            'c_teams' => $this->ppLoaded('c_teams'),
            'f8_teams' => $this->ppLoaded('f8_teams'),
        ]);

        try {
            if ($this->isPolicyC()) {
                $this->afternoonPolicyC();

                return;
            }

            $this->beginAfternoonForPrograms();

            $blocks = app(AfternoonBlockOrderService::class)
                ->resolvedBlocks((int) $this->pp('g_plan'));

            foreach ($blocks as $block) {
                if (! $this->afternoonBlockShouldEmit($block)) {
                    continue;
                }

                $this->syncSharedAfternoonClock();
                $this->emitAfternoonBlock($block);
                $this->syncSharedAfternoonClock();
            }

            $this->endAfternoonForPrograms();
        } catch (\Throwable $e) {
            Log::error('PlanGeneratorCore: Error in afternoon', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException("Fehler beim Generieren des Nachmittags: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Policy C: independent afternoon walks (filter by first_program), then leave clocks
     * for joint awards (later-of-both in awardsForPrograms).
     */
    private function afternoonPolicyC(): void
    {
        $blocks = app(AfternoonBlockOrderService::class)
            ->resolvedBlocks((int) $this->pp('g_plan'));

        $this->challenge->beginAfternoon();
        foreach ($blocks as $block) {
            if ((int) ($block->first_program ?? 0) !== FirstProgram::CHALLENGE->value) {
                continue;
            }
            if (! $this->afternoonBlockShouldEmit($block)) {
                continue;
            }
            $this->emitAfternoonBlock($block);
        }
        $this->challenge->endAfternoon();

        $this->future->beginAfternoon();
        foreach ($blocks as $block) {
            if ((int) ($block->first_program ?? 0) !== FirstProgram::FUTURE_8->value) {
                continue;
            }
            if (! $this->afternoonBlockShouldEmit($block)) {
                continue;
            }
            $this->emitAfternoonBlock($block);
        }
        $this->future->endAfternoon();
    }

    private function emitAfternoonBlock(object $block): void
    {
        match ((string) $block->code) {
            'c_presentations' => $this->challenge?->presentations(),
            'f8_presentations' => $this->future?->presentations(),
            'r_final_16' => $this->insertChallengeFinalRound(16),
            'r_final_8' => $this->insertChallengeFinalRound(8),
            'r_final_4' => $this->insertChallengeFinalRound(4),
            'r_final_2' => $this->insertChallengeFinalRound(2),
            'f8_round_4' => $this->insertFutureEmptyRound(4),
            'f8_round_5' => $this->insertFutureEmptyRound(5),
            default => null,
        };
    }

    private function beginAfternoonForPrograms(): void
    {
        if ($this->challenge !== null && $this->future !== null) {
            $this->syncSharedCeremonyClock();

            $start = $this->challenge->cTime()->current();
            $this->challenge->beginAfternoon();
            $chEnd = $this->challenge->rTime()->current();

            $this->future->cTime()->set($start);
            $this->future->beginAfternoon();
            $f8End = $this->future->rTime()->current();

            $end = $chEnd > $f8End ? $chEnd : $f8End;
            $this->challenge->rTime()->set($end);
            $this->future->rTime()->set($end);

            return;
        }

        $this->lead->beginAfternoon();
    }

    private function endAfternoonForPrograms(): void
    {
        if ($this->challenge !== null && $this->future !== null) {
            $this->syncSharedAfternoonClock();
            $start = $this->challenge->rTime()->current();

            $this->challenge->endAfternoon();
            $chC = $this->challenge->cTime()->current();

            $this->future->rTime()->set($start);
            $this->future->endAfternoon();
            $f8C = $this->future->cTime()->current();

            $later = $chC > $f8C ? $chC : $f8C;
            $this->challenge->cTime()->set($later);
            $this->future->cTime()->set($later);
            $this->challenge->rTime()->set($later);
            $this->future->rTime()->set($later);

            return;
        }

        $this->lead->endAfternoon();
    }

    /** Shared stage clock for Nachmittag blocks (both programs' rTime). */
    private function syncSharedAfternoonClock(): void
    {
        if ($this->challenge === null || $this->future === null) {
            return;
        }

        $later = $this->challenge->rTime()->current();
        if ($this->future->rTime()->current() > $later) {
            $later = $this->future->rTime()->current();
        }
        $this->challenge->rTime()->set($later);
        $this->future->rTime()->set($later);
    }

    /** Shared ceremony clock before joint awards / afternoon start. */
    private function syncSharedCeremonyClock(): void
    {
        if ($this->challenge === null || $this->future === null) {
            return;
        }

        $later = $this->challenge->cTime()->current();
        if ($this->future->cTime()->current() > $later) {
            $later = $this->future->cTime()->current();
        }
        $this->challenge->cTime()->set($later);
        $this->future->cTime()->set($later);
    }

    private function insertChallengeFinalRound(int $teamCount): void
    {
        $this->challenge?->insertFinalRound($teamCount);
    }

    private function insertFutureEmptyRound(int $round): void
    {
        $this->future?->insertEmptyGameRound($round);
    }

    private function afternoonBlockShouldEmit(object $block): bool
    {
        $code = (string) $block->code;

        if ($code === 'r_final_16' && ! $this->pp('g_finale')) {
            return false;
        }

        if ($block->afternoon_parameter === null) {
            return true;
        }

        if (! $this->params->has($code)) {
            return false;
        }

        $value = $this->pp($code);

        return $value !== 0 && $value !== '0' && $value !== false && $value !== null;
    }
}
