<?php

namespace App\Core;

use App\Enums\ExploreMode;
use App\Support\IntegratedExploreState;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use Illuminate\Support\Facades\Log;

class PlanGeneratorCore
{
    private ActivityWriter $writer;

    private ChallengeGenerator $challenge;

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
     * Ceremony recipes (Challenge leads; Explore is a wrapper). Same call order as before.
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
        $cMode = (int) $this->pp('c_mode');
        $eMode = (int) $this->pp('e_mode');

        if ($cMode === 1) {
            $this->challenge = new ChallengeGenerator(
                $this->writer,
                $this->params,
                $this->integratedExplore
            );

            match ($eMode) {
                ExploreMode::NONE->value => $this->recipeChallengeOnly(),
                ExploreMode::INTEGRATED_MORNING->value => $this->recipeIntegratedMorning(),
                ExploreMode::INTEGRATED_AFTERNOON->value => $this->recipeIntegratedAfternoon(),
                ExploreMode::HYBRID_BOTH->value => $this->recipeHybridBoth(),
                ExploreMode::DECOUPLED_MORNING->value,
                ExploreMode::DECOUPLED_AFTERNOON->value,
                ExploreMode::DECOUPLED_BOTH->value => $this->recipeDecoupled($eMode),
                default => null,
            };

            return;
        }

        if ($eMode === ExploreMode::NONE->value) {
            Log::warning('PlanGeneratorCore: Both programs disabled (e_mode=0, c_mode=0) - generating empty plan');

            return;
        }

        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );

        $this->recipeExploreOnly($eMode);
    }

    /** Challenge only: opening → main (judging ∥ RG) → finals → awards. */
    private function recipeChallengeOnly(): void
    {
        $this->challenge->openingsAndBriefings();
        $this->challenge->main();
        $this->challenge->robotGameFinals();
        $this->challenge->awards();
    }

    /**
     * Joint opening. Explore morning judging. After RG1, Explore awards (handoff). Challenge awards alone.
     */
    private function recipeIntegratedMorning(): void
    {
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );

        $this->challenge->openingsAndBriefings(true);
        $this->explore->openingsAndBriefings(1);
        $this->explore->judgingAndDeliberations(1);

        $afterRG1Callback = function (TimeCursor $rTime) {
            $rg1End = $this->integratedExplore->rg1End ?? $rTime->current();
            $deliberationsEnd = $this->integratedExplore->deliberationsEnd;
            $earliestStart = $rg1End;
            if ($deliberationsEnd !== null && $deliberationsEnd > $earliestStart) {
                $earliestStart = $deliberationsEnd;
            }

            $this->explore->integratedActivity(1, $earliestStart);

            $exploreEnd = $this->eTime->current();
            $exploreEnd->modify('+'.((int) $this->pp('e_ready_awards')).' minutes');
            $rTime->advanceToLater($exploreEnd);
        };

        $this->challenge->main(true, $afterRG1Callback);

        $this->challenge->robotGameFinals();
        $this->challenge->awards();
    }

    /**
     * Challenge opening. After RG1, Explore afternoon opening (handoff). Joint awards.
     */
    private function recipeIntegratedAfternoon(): void
    {
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );

        $this->challenge->openingsAndBriefings();
        $this->challenge->main(true);
        $this->explore->integratedActivity(2);
        $this->explore->judgingAndDeliberations(2);
        $this->challenge->robotGameFinals();
        $this->challenge->awards(true);
    }

    /**
     * Joint opening. Explore morning full day (own awards). Joint Challenge awards. Explore afternoon opening after RG1, then judging (no Explore afternoon awards).
     */
    private function recipeHybridBoth(): void
    {
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );

        $this->challenge->openingsAndBriefings(true);
        $this->challenge->main();

        $this->explore->openingsAndBriefings(1);
        $this->explore->judgingAndDeliberations(1);
        $this->explore->awards(1);

        $this->challenge->robotGameFinals();
        $this->challenge->awards(true);

        $this->explore->integratedActivity(2);
        $this->explore->judgingAndDeliberations(2);
    }

    /** Full Challenge day, then Explore group(s) fully decoupled (own opening, judging, awards). */
    private function recipeDecoupled(int $eMode): void
    {
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExplore,
            $this->eTime
        );

        $this->challenge->openingsAndBriefings();
        $this->challenge->main();
        $this->challenge->robotGameFinals();
        $this->challenge->awards();

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
}
