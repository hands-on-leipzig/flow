<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Enums\ExploreMode;

class FinaleGenerator
{
    use UsesPlanParameter;

    private ActivityWriter $writer;
    private PlanParameter $params;

    public function __construct(ActivityWriter $writer, PlanParameter $params)
    {
        $this->writer = $writer;
        $this->params = $params;

        Log::info('FinaleGenerator constructed', [
            'plan_id' => $params->get('g_plan'),
            'c_teams' => $params->get('c_teams'),
        ]);
    }

    /**
     * Main generation method for finale events
     * Handles complete 2-day generation: Day 1 (Live Challenge + Test Rounds) and Day 2 (Main Competition)
     * 
     * @param int $eMode Explore mode (if Explore teams present)
     * @param IntegratedExploreState $integratedExplore Shared state for Explore integration
     */
    public function generate(int $eMode, IntegratedExploreState $integratedExplore): void
    {
        Log::info("FinaleGenerator: Start generation", [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $eMode,
        ]);

        // Generate Day 1: Live Challenge + Test Rounds
        $this->generateDay1();

        // Generate Day 2: Main Competition
        $this->generateDay2($integratedExplore);

        // Generate Explore activities (if enabled)
        if ($eMode != ExploreMode::NONE->value) {
            $this->generateExplore($eMode, $integratedExplore);
        }

        Log::info("FinaleGenerator: Generation complete", [
            'plan_id' => $this->pp('g_plan'),
        ]);
    }

    /**
     * Generate Day 1 activities
     * - Opening ceremony
     * - Live Challenge (parallel to TR1)
     * - Break for robot modifications
     * - Live Challenge (parallel to TR2)
     * - Briefings
     * - Parties
     */
    private function generateDay1(): void
    {
        Log::info("FinaleGenerator: Generating Day 1", [
            'plan_id' => $this->pp('g_plan'),
        ]);

        // TODO: Implement Day 1 generation
        // - Opening ceremony (f_start_opening_day_1, f_duration_opening_day_1)
        // - Live Challenge activities (parallel to test rounds)
        // - Test Round 1 (TR1)
        // - Break for modifications (f_ready_action_day_1)
        // - Test Round 2 (TR2)
        // - Jury briefing (f_duration_briefing_day_1)
        // - Parties / social events
    }

    /**
     * Generate Day 2 activities
     * - Opening ceremony
     * - 5 rounds of judging (5 lanes, same team assignment as Day 1)
     * - 3 Robot Game rounds (R1, R2, R3 - no test rounds on Day 2)
     * - Robot Game Finals (16→8→4→2)
     * - Awards ceremony
     * 
     * @param IntegratedExploreState $integratedExplore Shared state for potential Explore integration
     */
    private function generateDay2(IntegratedExploreState $integratedExplore): void
    {
        Log::info("FinaleGenerator: Generating Day 2", [
            'plan_id' => $this->pp('g_plan'),
        ]);

        // TODO: Implement Day 2 generation
        // - Opening ceremony (f_start_opening_day_3, f_duration_opening_day_3)
        // - 5 rounds of judging with 5 lanes
        // - 3 Robot Game rounds (no test rounds - those were Day 1)
        // - Robot Game Finals:
        //   - Round of 16 (25 teams → 16)
        //   - Quarter-finals (16 → 8)
        //   - Semi-finals (8 → 4)
        //   - Finals (4 → 2)
        // - Awards ceremony
    }

    /**
     * Generate Explore activities for finale
     * Finale only supports DECOUPLED modes for Explore
     * 
     * @param int $eMode Explore mode
     * @param IntegratedExploreState $integratedExplore Shared state
     */
    private function generateExplore(int $eMode, IntegratedExploreState $integratedExplore): void
    {
        Log::info("FinaleGenerator: Generating Explore activities", [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $eMode,
        ]);

        // Initialize Explore generator
        $explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $integratedExplore
        );

        // Run explore activities (decoupled from Challenge)
        $explore->openingsAndBriefings();
        $explore->judgingAndDeliberations();
        $explore->awards();

        // Support DECOUPLED_BOTH if needed (two separate sessions)
        if ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
            $explore->setMode(ExploreMode::DECOUPLED_AFTERNOON->value);

            $explore->openingsAndBriefings();
            $explore->judgingAndDeliberations();
            $explore->awards();
        }
    }
}
