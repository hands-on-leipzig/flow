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
     */
    public function generate(): void
    {
        Log::info("FinaleGenerator: Start generation", [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $this->pp('e_mode'),
        ]);

        // Generate Day 1: Live Challenge + Test Rounds
        $this->generateDay1();

        // Generate Day 2: Main Competition + Explore (if enabled)
        $this->generateDay2();

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
     * - Explore activities (if enabled) based on e_mode
     */
    private function generateDay2(): void
    {
        Log::info("FinaleGenerator: Generating Day 2", [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $this->pp('e_mode'),
        ]);

        $eMode = $this->pp('e_mode');

        // Assume Explore is present (e_mode > 0) for finale events
        // Mirror the structure from PlanGeneratorCore but adapted for finale Day 2

        if ($eMode == ExploreMode::INTEGRATED_MORNING->value) {
            // Challenge + Explore integrated morning
            $this->generateDay2IntegratedMorning();
            
        } elseif ($eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
            // Challenge + Explore integrated afternoon
            $this->generateDay2IntegratedAfternoon();
            
        } elseif (in_array($eMode, [
            ExploreMode::DECOUPLED_MORNING->value,
            ExploreMode::DECOUPLED_AFTERNOON->value,
            ExploreMode::DECOUPLED_BOTH->value
        ])) {
            // Challenge + Explore decoupled
            $this->generateDay2Decoupled();
        }
    }

    /**
     * Generate Day 2 with Explore integrated in morning
     * Opening → Explore judging → Challenge judging/RG → Explore activity → Finals → Awards
     */
    private function generateDay2IntegratedMorning(): void
    {
        // TODO: Implement Day 2 Integrated Morning
        // - Opening ceremony (Challenge + Explore together)
        // - Explore: Opening & Briefings
        // - Explore: Judging & Deliberations
        // - Challenge: 5 rounds of judging + 3 RG rounds
        // - Explore: Integrated activity
        // - Robot Game Finals (16→8→4→2)
        // - Challenge: Awards
    }

    /**
     * Generate Day 2 with Explore integrated in afternoon
     * Opening → Challenge judging/RG → Explore activity → Explore judging → Finals → Awards (with Explore)
     */
    private function generateDay2IntegratedAfternoon(): void
    {
        // TODO: Implement Day 2 Integrated Afternoon
        // - Opening ceremony (Challenge only)
        // - Challenge: 5 rounds of judging + 3 RG rounds
        // - Explore: Integrated activity
        // - Explore: Judging & Deliberations
        // - Robot Game Finals (16→8→4→2)
        // - Awards (Challenge + Explore together)
    }

    /**
     * Generate Day 2 with Explore decoupled
     * Challenge and Explore run in parallel/separate time slots
     */
    private function generateDay2Decoupled(): void
    {
        // TODO: Implement Day 2 Decoupled
        // - Opening ceremony (Challenge only)
        // - Challenge: 5 rounds of judging + 3 RG rounds
        // - Robot Game Finals (16→8→4→2)
        // - Challenge: Awards
        // 
        // Explore runs in parallel/separate:
        // - Explore: Opening & Briefings (session 1)
        // - Explore: Judging & Deliberations (session 1)
        // - Explore: Awards (session 1)
        //
        // If DECOUPLED_BOTH:
        // - Explore: Opening & Briefings (session 2)
        // - Explore: Judging & Deliberations (session 2)
        // - Explore: Awards (session 2)
    }
}
