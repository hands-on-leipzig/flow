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
     * When both Challenge and Future are on, a GameRoundCoordinator runs Policy A mornings.
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

            return;
        }

        if ($eMode === ExploreMode::NONE->value) {
            Log::warning('PlanGeneratorCore: All programs disabled - generating empty plan');

            return;
        }

        $this->makeExplore();

        $this->recipeExploreOnly($eMode);
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
            Log::info('PlanGeneratorCore: dual Challenge-shaped morning (Policy A)', [
                'plan_id' => $this->pp('g_plan'),
                'f8_future_first' => (bool) $this->ppLoaded('f8_future_first'),
                'f8_per_round' => (bool) $this->ppLoaded('f8_per_round', true),
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
        $this->lead->awards();
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
            if ($this->future !== null) {
                $this->future->rTime()->advanceToLater($rTime->current());
            }
            if ($this->challenge !== null) {
                $this->challenge->rTime()->advanceToLater($rTime->current());
            }
        };

        $this->runMain(true, $afterRG1Callback);

        $this->afternoon();
        $this->lead->awards();
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
        $this->lead->awards(true);
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
        $this->lead->awards(true);

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
        $this->lead->awards();

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
     */
    private function openingsForPrograms(bool $jointOpening): void
    {
        $this->lead->openingsAndBriefings($jointOpening);

        if ($this->coordinator !== null && $this->future !== null && $this->challenge !== null) {
            // Lead is Challenge: Future briefings only (opening already joint/Challenge).
            $this->future->briefings($this->challenge->cTime()->current());
            $this->future->syncClocksFrom($this->challenge);
        }
    }

    private function runMain(bool $explore = false, ?callable $afterRG1Callback = null): void
    {
        if ($this->coordinator !== null) {
            $this->coordinator->main($explore, $afterRG1Callback);

            return;
        }

        $this->lead->main($explore, $afterRG1Callback);
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
     * Walk the Nachmittag list (presentations, finals / Future extra rounds). Awards stay in the recipes after this.
     */
    private function afternoon(): void
    {
        Log::info('PlanGeneratorCore::afternoon', [
            'plan_id' => $this->pp('g_plan'),
            'c_teams' => $this->ppLoaded('c_teams'),
            'f8_teams' => $this->ppLoaded('f8_teams'),
        ]);

        try {
            $this->lead->beginAfternoon();
            if ($this->challenge !== null && $this->future !== null && $this->lead !== $this->future) {
                $this->future->beginAfternoon();
                // Keep both afternoon clocks aligned to the later results pause.
                $later = $this->challenge->rTime()->current();
                if ($this->future->rTime()->current() > $later) {
                    $later = $this->future->rTime()->current();
                }
                $this->challenge->rTime()->set($later);
                $this->future->rTime()->set($later);
            }

            $blocks = app(AfternoonBlockOrderService::class)
                ->resolvedBlocks((int) $this->pp('g_plan'));

            foreach ($blocks as $block) {
                if (! $this->afternoonBlockShouldEmit($block)) {
                    continue;
                }

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

            $this->lead->endAfternoon();
            if ($this->challenge !== null && $this->future !== null && $this->lead !== $this->future) {
                $this->future->endAfternoon();
            }
        } catch (\Throwable $e) {
            Log::error('PlanGeneratorCore: Error in afternoon', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException("Fehler beim Generieren des Nachmittags: {$e->getMessage()}", 0, $e);
        }
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
