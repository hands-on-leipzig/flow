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
     * Note: Day 2 is generated first to create judging and robot game match plans.
     * Day 1 then copies the team-to-lane assignments from Day 2.
     */
    public function generate(): void
    {
        Log::info("FinaleGenerator: Start generation", [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $this->pp('e_mode'),
        ]);

        // Generate Day 2 FIRST: Creates judging lanes and robot game match plan
        // This establishes the team-to-lane assignments that Day 1 will reuse
        $this->generateDay2();

        // Generate Day 1: Live Challenge + Test Rounds
        // Uses the same team-to-lane assignments as Day 2
        $this->generateDay1();

        Log::info("FinaleGenerator: Generation complete", [
            'plan_id' => $this->pp('g_plan'),
        ]);
    }

    /**
     * Generate Day 1 activities (Live Challenge Day)
     * Timeline: Briefings → Opening → LC/TR1 → Break → LC/TR2 → Parties
     * 
     * IMPORTANT: Called AFTER generateDay2() to reuse team-to-lane assignments
     */
    private function generateDay1(): void
    {
        Log::info("FinaleGenerator: Generating Day 1", [
            'plan_id' => $this->pp('g_plan'),
        ]);

        // Initialize time cursor for Day 1
        // Start at opening ceremony time, then work backwards for briefings
        $day1Time = new TimeCursor($this->pp('g_date'));
        $day1Time->setTime($this->pp('f_start_opening_day_1'));

        // Store opening start time for later use
        $openingStart = clone $day1Time->current();

        // === BRIEFINGS (before opening, working backwards) ===
        $this->generateDay1Briefings($openingStart);

        // === OPENING CEREMONY ===
        // Reset time to opening start
        $day1Time = new TimeCursor($openingStart);
        
        $this->writer->withGroup('f_opening_day_1', function () use ($day1Time) {
            $this->writer->insertActivity('f_opening_day_1', $day1Time, $this->pp('f_duration_opening_day_1'));
        });
        $day1Time->addMinutes($this->pp('f_duration_opening_day_1'));

        // === TRANSITION TO ACTIVITIES ===
        $day1Time->addMinutes($this->pp('f_ready_action_day_1'));

        // TODO: Implement remaining Day 1 activities
        // Day 2 has already been generated, so we can now:
        // - Read team-to-lane assignments from Day 2 judging
        // - Read match plan from Day 2 robot game
        // - Generate Live Challenge activities (parallel to test rounds)
        // - Generate Test Round 1 (TR1) using Day 2 team assignments
        // - Break for robot modifications
        // - Generate Test Round 2 (TR2) using Day 2 team assignments
        // - Parties / social events
    }

    /**
     * Generate Day 1 briefings (working backwards from opening time)
     * Three briefings: Coaches, Robot Game Referees, Live Challenge Judges
     */
    private function generateDay1Briefings(\DateTime $openingStart): void
    {
        // Coach briefing (c_briefing)
        $this->writer->withGroup('c_briefing', function () use ($openingStart) {
            $cursor = new TimeCursor($openingStart);
            $cursor->subMinutes($this->pp('c_duration_briefing') + $this->pp('c_ready_opening'));
            $this->writer->insertActivity('c_briefing', $cursor, $this->pp('c_duration_briefing'));
        });

        // Robot Game referee briefing (r_briefing)
        $this->writer->withGroup('r_briefing', function () use ($openingStart) {
            $cursor = new TimeCursor($openingStart);
            $cursor->subMinutes($this->pp('r_duration_briefing') + $this->pp('c_ready_opening'));
            $this->writer->insertActivity('r_briefing', $cursor, $this->pp('r_duration_briefing'));
        });

        // Live Challenge judge briefing (lc_briefing)
        $this->writer->withGroup('lc_briefing', function () use ($openingStart) {
            $cursor = new TimeCursor($openingStart);
            $cursor->subMinutes($this->pp('lc_duration_briefing') + $this->pp('c_ready_opening'));
            $this->writer->insertActivity('lc_briefing', $cursor, $this->pp('lc_duration_briefing'));
        });
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
