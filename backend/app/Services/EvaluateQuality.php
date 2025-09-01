<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\QRun;
use App\Models\QPlan;
use App\Models\QPlanTeam;
use App\Models\MParameter;
use App\Models\PlanParamValue;
use App\Models\Plan;
use App\Models\Event;
use App\Models\MSupportedPlan;
use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpParser\Node\Expr\FuncCall;

class EvaluateQuality
{

    public function generateQPlansFromSelection(int $runId): void
    {
        $RP_NAME = '!!! QPlan RP - nur für den Qualitätstest verwendet !!!';
        $EVENT_NAME = '!!! QPlan Event - nur für den Qualitätstest verwendet !!!';
       
        Log::info("Erzeugung der qPlans für Run ID $runId startet.");

        // Read q_run (Name + Selection)
        $qRun = DB::table('q_run')->where('id', $runId)->first();

        if (!$qRun) {
            throw new \Exception("q_run with ID $runId not found");
        }

        

        try {
            $selection = json_decode($qRun->selection, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON in q_run.selection (ID $runId): " . $e->getMessage());
        }

        // Get all allowed parameters once to be used in the loop below
        $parameters = MParameter::all()->keyBy('name');

        // Sicherstellen, dass der spezielle Regionalpartner existiert
        $regionalPartner = DB::table('regional_partner')->where('name', $RP_NAME)->first();

        if (!$regionalPartner) {
            $regionalPartnerId = DB::table('regional_partner')->insertGetId([
                'name' => $RP_NAME,
                'region' => 0,
            ]);
            Log::info("RP für Qualitätstest neu angelegt mit ID $regionalPartnerId");
        } else {
            $regionalPartnerId = $regionalPartner->id;
        }

        // Sicherstellen, dass das spezielle Event existiert
        $event = DB::table('event')->where('name', $EVENT_NAME)->first();

        if (!$event) {
            $seasonId = DB::table('m_season')
                ->orderByDesc('year')
                ->value('id');
            $eventId = DB::table('event')->insertGetId([
                'name' => $EVENT_NAME,
                'regional_partner' => $regionalPartnerId,
                'level' => 1,
                'season' => $seasonId,
                'date' => Carbon::today(),
                'days' => 1,
            ]);
            Log::info("Event für Qualitätstest neu angelegt mit ID $eventId");
        } else {
            $eventId = $event->id;
        }

        // Read m_supported_plan and filter by selection
        $supportedPlans = MSupportedPlan::where('first_program', 3)
            ->orderBy('teams')
            ->orderBy('lanes')
            ->orderBy('tables')
            ->get();

        foreach ($supportedPlans as $plan) {
            if (!$this->isPlanSupported($plan, $selection)) {
                continue;
            }

            $rounds = (int) ceil($plan->teams / $plan->lanes);

            // Two versions: with and without robot check
            foreach ([0, 1] as $robotCheck) {
                $suffix = $robotCheck === 1 ? ' RC an' : ' RC aus';

                // Create a new plan and get its ID
                $newPlan = Plan::create([
                    'name' => "{$plan->teams}-{$plan->lanes}-{$plan->tables} ({$rounds}){$suffix}",
                    'event' => $eventId,
                    'created' => Carbon::now(),
                    'last_change' => Carbon::now(),
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
                    'name' => $newPlan->name,
                    'c_teams' => $plan->teams,
                    'r_tables' => $plan->tables ?? 0,
                    'j_lanes' => $plan->lanes,
                    'j_rounds' => $rounds,
                    'r_robot_check' => $robotCheck,
                    'r_duration_robot_check' => 0,
                    'c_duration_transfer' => $parameters['c_duration_transfer']->value,
                    'q1_ok_count' => null,
                    'q2_ok_count' => null,
                    'q3_ok_count' => null,
                    'q4_ok_count' => null,
                    'q5_idle_avg' => null,
                    'q5_idle_stddev' => null,
                ]);

                        
            } // End foreach robot check yes/no
             
        }  // End foreach supported plan

        // Update q_run with the total number of q_plans created
        $qPlansTotal = QPlan::where('q_run', $runId)->count();
        QRun::where('id', $runId)->update([
            'qplans_total' => $qPlansTotal,
        ]);

    }

    public function generateQPlansFromQPlans(int $newRunId, array $planIds)
    {
        foreach ($planIds as $planId) {
            $original = QPlan::find($planId);

            if (!$original) {
                Log::warning("QPlan $planId nicht gefunden, wird übersprungen.");
                continue;
            }

            // Plan-Datensatz kopieren
            $originalPlan = Plan::find($original->plan);
            if (!$originalPlan) {
                Log::warning("Plan {$original->plan} nicht gefunden, QPlan $planId wird übersprungen.");
                continue;
            }

            $planCopy = $originalPlan->replicate();
            $planCopy->save();

            // QPlan-Datensatz kopieren und mit neuem Plan verknüpfen
            $copy = $original->replicate();
            $copy->q_run = $newRunId;
            $copy->plan = $planCopy->id;

            // Q-Werte nullen
            $copy->q1_ok_count = null;
            $copy->q2_ok_count = null;
            $copy->q3_ok_count = null;
            $copy->q4_ok_count = null;
            $copy->q5_idle_avg = null;
            $copy->q5_idle_stddev = null;
            $copy->calculated = 0;

            $copy->save();

            // Parameterwerte kopieren
            $paramValues = PlanParamValue::where('plan', $originalPlan->id)->get();

            foreach ($paramValues as $param) {
                $newParam = $param->replicate();
                $newParam->plan = $planCopy->id;
                $newParam->save();
            }
        }

        // qplans_total setzen
        QRun::where('id', $newRunId)->update([
            'qplans_total' => QPlan::where('q_run', $newRunId)->count(),
        ]);
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
        


    /**
     * Main entry point to evaluate all quality metrics (Q1–Q4) for a given plan.
     */
    public function evaluate(int $qPlanId): void
    {
        $activities = $this->prepareEvaluationData($qPlanId);
   
        $this->calculateQ1($qPlanId, $activities);
        $this->calculateQ2($qPlanId);
        $this->calculateQ3($qPlanId);
        $this->calculateQ4($qPlanId);
        $this->calculateQ5($qPlanId);
    }


    // Evaluate quality for a given plan ID by looking up the corresponding QPlan entry.
    // This called from the GeneratePlan job after plan generation.
    public function evaluatePlanId(int $planId): void
    {
        $qPlan = \App\Models\QPlan::where('plan', $planId)->first();

        if (!$qPlan) {
            Log::warning("Kein QPlan für Plan ID $planId gefunden – Evaluation übersprungen");
            return;
        }

        $this->evaluate($qPlan->id);
}

    /**
     * Load all relevant activities for a given plan, including joins to group and type info.
     */
    private function prepareEvaluationData(int $qPlanId): Collection
    {
        // Fetch the plan ID from q_plan
        $planId = DB::table('q_plan')
        ->where('id', $qPlanId)
        ->value('plan');
        
        // Fetch activities related to the given q_plan
       $activities = Activity::query()
        ->select([
            'activity.start',
            'activity.end',
            'activity.jury_lane',
            'activity.jury_team',
            'activity.table_1',
            'activity.table_1_team',
            'activity.table_2',
            'activity.table_2_team',
            'activity.activity_type_detail as activity_atd',
            'activity_group.activity_type_detail as activity_group_atd',
        ])
        ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
        ->where('activity_group.plan', $planId)
        ->whereIn('activity_group.activity_type_detail', [8, 9, 10, 11, 20])
        ->whereIn('activity.activity_type_detail', [15, 16, 17])
        ->orderBy('activity.start')
        ->get();

        // Delete all previous entries for this q_plan in q_plan_team
        DB::table('q_plan_team')->where('q_plan', $qPlanId)->delete();

        // Get number of teams for this plan
        $teamCount = $this->getParameterValueForPlan($qPlanId, 'c_teams');

        // Insert default rows in q_plan_team
        for ($team = 1; $team <= $teamCount; $team++) {
            DB::table('q_plan_team')->insert([
                'q_plan' => $qPlanId,
                'team' => $team,
                
                'q1_ok' => 0,
                'q1_transition_1_2' => 0,
                'q1_transition_2_3' => 0,
                'q1_transition_3_4' => 0,
                'q1_transition_4_5' => 0,

                'q2_ok' => 0,
                'q2_tables' => 0,

                'q3_ok' => 0,
                'q3_teams' => 0,
                
                'q4_ok' => 0,
                
                'q5_idle_0_1' => 0,
                'q5_idle_1_2' => 0,
                'q5_idle_2_3' => 0,
                'q5_idle_avg' => 0,

            ]);
        }

        // Delete all previous entries for this q_plan in q_plan_match
        DB::table('q_plan_match')->where('q_plan', $qPlanId)->delete();

        // Filter only match activities (activity_atd = 15)
        $matchActivities = $activities->filter(function ($a) {
            return $a->activity_atd === 15;
        });

        // Map activity_group_atd to round
        $roundMap = [8 => 0, 9 => 1, 10 => 2, 11 => 3];
        $currentRound = null;
        $matchCounter = 0;

        foreach ($matchActivities as $activity) {
            $round = $roundMap[$activity->activity_group_atd] ?? null;
            if ($round === null) {
                continue; // skip unknown round
            }

            // Reset counter when round changes
            if ($round !== $currentRound) {
                $currentRound = $round;
                $matchCounter = 1;
            } else {
                $matchCounter++;
            }

            // Map null to 0 for teams
            $team1 = is_null($activity->table_1_team) ? 0 : $activity->table_1_team;
            $team2 = is_null($activity->table_2_team) ? 0 : $activity->table_2_team;

            // Insert row into q_plan_match
            DB::table('q_plan_match')->insert([
                'q_plan' => $qPlanId,
                'round' => $round,
                'match_no' => $matchCounter,
                'table_1' => $activity->table_1,
                'table_2' => $activity->table_2,
                'table_1_team' => $team1,
                'table_2_team' => $team2,
            ]);
        }

        return $activities;
    }

    private function getParameterValueForPlan(int $qPlanId, string $name): int
    {
        // Join über q_plan → plan → plan_param_value → m_parameter
        $value = DB::table('q_plan')
            ->join('plan', 'q_plan.plan', '=', 'plan.id')
            ->join('plan_param_value', 'plan_param_value.plan', '=', 'plan.id')
            ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
            ->where('q_plan.id', $qPlanId)
            ->where('m_parameter.name', $name)
            ->value('plan_param_value.set_value');

        if ($value !== null) {
            return (int)$value;
        }

        // Fallback: default aus m_parameter
        $default = DB::table('m_parameter')
            ->where('name', $name)
            ->value('value');

        return (int)$default;
    }


    /**
     * Evaluate Q1: Check for minimum gap between the 5 relevant activities.
     */
    private function calculateQ1(int $qPlanId, Collection $activities): void
    {
        $minGap = $this->getParameterValueForPlan($qPlanId, 'c_duration_transfer');
        $teamCount = $this->getParameterValueForPlan($qPlanId, 'c_teams');

        for ($team = 1; $team <= $teamCount; $team++) {
            // Filter activities relevant for this team
            $teamActivities = $activities->filter(function ($a) use ($team) {
                if ($a->activity_atd === 17) {
                    return $a->jury_team === $team;
                } elseif ($a->activity_atd === 15) {
                    return $a->table_1_team === $team || $a->table_2_team === $team;
                }
                return false;
            })->values();

            // Calculate all 4 gaps and check if all are >= minGap
            $allTransitions = [];
            $allGapsOk = true;

            for ($i = 1; $i < $teamActivities->count(); $i++) {
                $prev = new \DateTime($teamActivities[$i - 1]->end);
                $curr = new \DateTime($teamActivities[$i]->start);
                $gap = ($curr->getTimestamp() - $prev->getTimestamp()) / 60; // gap in minutes

                $allTransitions[$i] = $gap;

                // error_log("T{$team} | {$prev->format('H:i')} → {$curr->format('H:i')} | Δ {$gap} min");

                if ($gap < $minGap) {
                    $allGapsOk = false;
                }
            }

            // Store result in q_plan_team
            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q1_ok' => $allGapsOk,
                    'q1_transition_1_2' => $allTransitions[1] ?? 0,
                    'q1_transition_2_3' => $allTransitions[2] ?? 0,
                    'q1_transition_3_4' => $allTransitions[3] ?? 0,
                    'q1_transition_4_5' => $allTransitions[4] ?? 0,
                ]);
        }

        // Count number of teams that passed Q1
        $ok_count = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q1_ok', true)
            ->count();

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update(['q1_ok_count' => $ok_count]);

    }

    /**
     * Evaluate Q2: Check how many different tables the team played on.
     */
    private function calculateQ2(int $qPlanId): void
    {
        $tablesAvailable = $this->getParameterValueForPlan($qPlanId, 'r_tables');

        $matches = DB::table('q_plan_match')
            ->where('q_plan', $qPlanId)
            ->whereIn('round', [1, 2, 3])
            ->get();

        $teamTables = [];

        foreach ($matches as $match) {
            foreach (['table_1_team' => 'table_1', 'table_2_team' => 'table_2'] as $teamKey => $tableKey) {
                $team = $match->$teamKey;
                if ($team === null || $team == 0) {
                    continue;
                }

                $teamTables[$team][] = $match->$tableKey;
            }
        }

        foreach ($teamTables as $team => $tables) {
            $distinctTables = count(array_unique($tables));

            $q2_ok = false;
            if ($tablesAvailable === 2 && $distinctTables === 2) {
                $q2_ok = true;
            } elseif ($tablesAvailable === 4 && $distinctTables === 3) {
                $q2_ok = true;
            }

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q2_ok' => $q2_ok,
                    'q2_tables' => $distinctTables,
                ]);
        }

        // Count number of teams that passed Q2
        $ok_count = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q2_ok', true)
            ->count();

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update(['q2_ok_count' => $ok_count]);


    }

    /**
     * Evaluate Q3: Check how many different opponents each team had.
     */
    private function calculateQ3(int $qPlanId): void
    {
        $matches = DB::table('q_plan_match')
            ->where('q_plan', $qPlanId)
            ->whereIn('round', [1, 2, 3])
            ->get();

        $opponents = [];

        foreach ($matches as $match) {
            $t1 = $match->table_1_team;
            $t2 = $match->table_2_team;

            $opponents[$t1][] = $t2;
            $opponents[$t2][] = $t1;
        }

        foreach ($opponents as $team => $faced) {
            $uniqueOpponents = count(array_unique($faced));

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q3_ok' => $uniqueOpponents === 3,
                    'q3_teams' => $uniqueOpponents,
                ]);
        }

        // Count number of teams that passed Q3
        $ok_count = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q3_ok', true)
            ->count();

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update(['q3_ok_count' => $ok_count]);

    }

    /**
     * Evaluate Q4: Check if test and first match are on the same table.
     */
    private function calculateQ4(int $qPlanId): void
    {
        $matches = DB::table('q_plan_match')
            ->where('q_plan', $qPlanId)
            ->whereIn('round', [0, 1])
            ->orderBy('round')
            ->get();

        $testTables = [];
        $round1Tables = [];

        foreach ($matches as $match) {
            foreach (['table_1_team' => 'table_1', 'table_2_team' => 'table_2'] as $teamKey => $tableKey) {
                $team = $match->$teamKey;
                $table = $match->$tableKey;

                if ($match->round === 0) {
                    $testTables[$team] = $table;
                } elseif ($match->round === 1) {
                    $round1Tables[$team] = $table;
                }
            }
        }

        foreach ($testTables as $team => $testTable) {
            $firstTable = $round1Tables[$team] ?? null;

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q4_ok' => $firstTable === $testTable,
                ]);
        }

        // Count number of teams that passed Q4
        $ok_count = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q4_ok', true)
            ->count();

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update(['q4_ok_count' => $ok_count]);        
    }

    /**
     * Evaluate Q5: Count idle matches between rounds.
     */
    private function calculateQ5(int $qPlanId): void
    {
        // Load all matches from rounds 0 to 3, sorted by round and match number
        $matches = DB::table('q_plan_match')
            ->where('q_plan', $qPlanId)
            ->whereIn('round', [0, 1, 2, 3])
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        // Group matches by round for quick access
        $matchesByRound = collect($matches)->groupBy('round');

        // Get all team numbers for this plan
        $teams = QPlanTeam::where('q_plan', $qPlanId)->pluck('team');

        // Store idle counts for later average calculation
        $teamIdleCounts = [];

        foreach ($teams as $team) {
            $idle = 0;
            $round = -1;

            $idleCounts = [
                'q5_idle_0_1' => 0,
                'q5_idle_1_2' => 0,
                'q5_idle_2_3' => 0,
            ];

            foreach ($matches as $match) {
                $isPlaying = ($match->table_1_team === $team || $match->table_2_team === $team);

                if ($isPlaying) {
                    $round++;

                    if ($round === 1) $idleCounts['q5_idle_0_1'] = $idle;
                    elseif ($round === 2) $idleCounts['q5_idle_1_2'] = $idle;
                    elseif ($round === 3) $idleCounts['q5_idle_2_3'] = $idle;

                    $idle = 0;
                } else {
                    $idle++;
                }
            }

            $avgIdle = ($idleCounts['q5_idle_0_1'] + $idleCounts['q5_idle_1_2'] + $idleCounts['q5_idle_2_3']) / 3;
            $teamIdleCounts[] = $avgIdle;

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q5_idle_0_1' => $idleCounts['q5_idle_0_1'],
                    'q5_idle_1_2' => $idleCounts['q5_idle_1_2'],
                    'q5_idle_2_3' => $idleCounts['q5_idle_2_3'],
                    'q5_idle_avg' => $avgIdle,
                ]);
        }

        // Calculate overall average and standard deviation
        $values = array_values($teamIdleCounts);
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);

        // Update q_plan summary fields
        QPlan::where('id', $qPlanId)
            ->update([
                'q5_idle_avg' => $mean,
                'q5_idle_stddev' => $stdDev,
            ]);
    }

}