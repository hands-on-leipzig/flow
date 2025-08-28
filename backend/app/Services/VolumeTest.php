<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Event;
use App\Models\MSupportedPlan;
use App\Models\Plan;
use App\Models\PlanParamValue;
use App\Models\MParameter;
use App\Models\QPlan;
use App\Models\QRun;
use Carbon\Carbon;
use App\Services\GeneratePlanService;
use App\Services\EvaluateQuality;
use Illuminate\Support\Facades\Log;

class VolumeTest
{
    public function generateQPlans(int $runId): void
    {
        Log::info("START: generateQPlans für Run ID $runId");

        // Read q_run (Name + Selection)
        $qRun = DB::table('q_run')->where('id', $runId)->first();

        if (!$qRun) {
            throw new \Exception("q_run with ID $runId not found");
        }

        $runName = $qRun->name;

        try {
            $selection = json_decode($qRun->selection, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON in q_run.selection (ID $runId): " . $e->getMessage());
        }

        // Get all allowed parameters once to be used in the loop below
        $parameters = MParameter::all()->keyBy('name');

        // Create one event (linked to this q_run)

        $regionalPartnerId = DB::table('regional_partner')->value('id');
        $seasonId = DB::table('m_season')->value('id');

        $event = Event::create([
            'name' => "QRun $runId: $runName",
            'regional_partner' => $regionalPartnerId,
            'level' => 1,
            'season' => $seasonId,
            'date' => Carbon::today(),
            'days' => 1,
            
        ]);

        Log::info("Event erstellt mit ID {$event->id} für Run ID $runId");

        // Read m_supported_plan and filter by selection
        $supportedPlans = MSupportedPlan::where('first_program', 3)->get();

        Log::info("Gefundene unterstützte Pläne: " . $supportedPlans->count());

        foreach ($supportedPlans as $plan) {
            if (!$this->isPlanSupported($plan, $selection)) {
                continue;
            }

            $rounds = (int) ceil($plan->teams / $plan->lanes);

            // Two version: with and without robot check
            foreach ([0, 1] as $robotCheck) {
                $suffix = $robotCheck === 1 ? ' RC an' : ' RC aus';

                // Create a new plan and get its ID
                $newPlan = Plan::create([
                    'name' => "{$plan->teams}-{$plan->lanes}-{$plan->tables} ({$rounds}){$suffix}",
                    'event_id' => $event->id,
                    'created' => now(),
                    'last_change' => now(),
                ]);

                $planId = $newPlan->id;

                Log::info("qPlan erstellt mit ID $planId ({$newPlan->name}) für Run ID $runId");

                // Add the parameter values for this plan
                PlanParamValue::create([
                    'parameter' => $parameters['c_teams']->id,
                    'plan' => $planId,
                    'set_value' => $plan->teams,
                ]);

                PlanParamValue::create([
                    'parameter' => $parameters['j_lanes']->id,
                    'plan' => $planId,
                    'set_value' => $plan->lanes,
                ]);

                PlanParamValue::create([
                    'parameter' => $parameters['r_tables']->id,
                    'plan' => $planId,
                    'set_value' => $plan->tables ?? 0,
                ]);

                PlanParamValue::create([
                    'parameter' => $parameters['r_robot_check']->id,
                    'plan' => $planId,
                    'set_value' => $robotCheck,
                ]);

                PlanParamValue::create([
                    'parameter' => $parameters['e_mode']->id,
                    'plan' => $planId,
                    'set_value' => 0,
                ]);

                // Create the corresponding q_plan entry
                $qPlan = QPlan::create([
                    'plan' => $planId,
                    'q_run' => $runId,
                    'c_teams' => $plan->teams,
                    'r_tables' => $plan->tables ?? 0,
                    'j_lanes' => $plan->lanes,
                    'r_robot_check' => $robotCheck,
                    'r_duration_robot_check' => 0,
                    'q1_ok_count' => null,
                    'q2_ok_count' => null,
                    'q3_ok_count' => null,
                    'q4_ok_count' => null,
                    'q5_idle_avg' => null,
                    'q5_idle_stddev' => null,
                ]);

                        
            } // End foreach robot check yes/no
             
        }  // End foreach supported plan

        // ⬇️ Statt Service direkt Job dispatchen – jetzt mit runId
        \App\Jobs\GeneratePlan::dispatch($runId);

        Log::info("GeneratePlan Job für Run ID $runId dispatcht");

    }

    private function isPlanSupported(MSupportedPlan $plan, array $selection): bool
    {
        $teams = $plan->teams;
        $lanes = $plan->lanes;
        $tables = $plan->tables;

        // Use defaults if keys are missing
        $min = $selection['min_teams'] ?? 0;
        $max = $selection['max_teams'] ?? PHP_INT_MAX;
        $juryLanes = $selection['jury_lanes'] ?? [];
        $tableOptions = $selection['tables'] ?? [];
        $juryRounds = $selection['jury_rounds'] ?? [];

        $rounds = (int) ceil($teams / $lanes);

        return
            $teams >= $min &&
            $teams <= $max &&
            in_array($lanes, $juryLanes) &&
            in_array($tables, $tableOptions) &&
            in_array($rounds, $juryRounds);
    }
        

    
}