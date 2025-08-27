<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\QPlan;
use App\Models\QPlanTeam;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class EvaluateQuality
{
    // For debugging: Dump all relevant activities for a given plan TODEL
 
    public function debugDump(int $qPlanId): array
    {
        // Load evaluation base data
        $activities = $this->prepareEvaluationData($qPlanId);

        // Calculate the Qs
        $this->calculateQ1($qPlanId, $activities);
        $this->calculateQ2($qPlanId);
        $this->calculateQ3($qPlanId);
        $this->calculateQ4($qPlanId);
        $this->calculateQ5($qPlanId);

        // Load all q_plan_team entries for the given q_plan_id
        $teams = QPlanTeam::where('q_plan', $qPlanId)->get();

        // Load all q_plan_match entries for the given q_plan_id
        $matches = DB::table('q_plan_match')
            ->where('q_plan', $qPlanId)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        return [
            'q_plan_team' => $teams->map(fn($t) => $t->toArray())->all(),
            'q_plan_match' => $matches->map(fn($m) => (array) $m)->all(),
            'activities' => $activities->toArray(),
        ];
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
            $idles = [];

            for ($round = 0; $round <= 2; $round++) {
                $idle = 0;

                $current = $matchesByRound->get($round, collect());
                $next = $matchesByRound->get($round + 1, collect());

                // Find first match in next round where team appears
                $nextIndex = $next->search(function ($m) use ($team) {
                    return $m->table_1_team === $team || $m->table_2_team === $team;
                });

                if ($nextIndex === false) {
                    // Team is not scheduled in next round at all
                    $idle = $current->count();
                } else {
                    // Count matches in current + early next round where team does not play
                    $beforeNext = $current->concat($next->slice(0, $nextIndex));
                    foreach ($beforeNext as $m) {
                        if ($m->table_1_team !== $team && $m->table_2_team !== $team) {
                            $idle++;
                        }
                    }
                }

                // Store value per team
                $idles[] = $idle;

                // Map round to DB column
                $column = match ($round) {
                    0 => 'q5_idle_0_1',
                    1 => 'q5_idle_1_2',
                    2 => 'q5_idle_2_3',
                };

                // Update idle count for this team and round
                QPlanTeam::where('q_plan', $qPlanId)
                    ->where('team', $team)
                    ->update([$column => $idle]);
            }

            // Calculate average idle for this team
            $avg = array_sum($idles) / count($idles);
            $teamIdleCounts[$team] = $avg;

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update(['q5_idle_avg' => $avg]);
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