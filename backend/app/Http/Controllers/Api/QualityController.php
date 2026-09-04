<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\QRun;
use App\Enums\FirstProgram;
use App\Support\ChallengeShapedParamMap;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\MActivityTypeDetail;


class QualityController extends Controller
{
    private array $atdCodeToId = [];

    private function atdId(string $code): ?int
    {
        if (!array_key_exists($code, $this->atdCodeToId)) {
            $this->atdCodeToId[$code] = DB::table('m_activity_type_detail')->where('code', $code)->value('id');
        }

        return $this->atdCodeToId[$code] ? (int) $this->atdCodeToId[$code] : null;
    }

    private function buildTwoDayTransferSummary(int $planId, int $teamCount, int $minGap): array
    {
        $day1Date = (new \App\Support\PlanParameter($planId))->get('g_date')->format('Y-m-d');
        $day2Date = (new \DateTime($day1Date))->modify('+1 day')->format('Y-m-d');

        $rMatchId = $this->atdId('r_match');
        $rCheckId = $this->atdId('r_check');
        $jWithTeamId = $this->atdId('j_with_team');
        $lcWithTeamId = $this->atdId('lc_with_team');

        $activities = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->where('ag.plan', $planId)
            ->whereIn('a.activity_type_detail', array_filter([$rMatchId, $rCheckId, $jWithTeamId, $lcWithTeamId]))
            ->orderBy('a.start')
            ->orderBy('a.id')
            ->get([
                'a.activity_type_detail as activity_atd',
                'a.start',
                'a.end',
                'a.jury_team',
                'a.table_1_team',
                'a.table_2_team',
            ]);

        $summary = [];
        for ($team = 1; $team <= $teamCount; $team++) {
            $teamActivities = $activities->filter(function ($a) use ($team, $jWithTeamId, $lcWithTeamId, $rMatchId, $rCheckId) {
                if ($a->activity_atd === $jWithTeamId || $a->activity_atd === $lcWithTeamId) {
                    return (int)$a->jury_team === $team;
                }
                if ($a->activity_atd === $rMatchId || $a->activity_atd === $rCheckId) {
                    return (int)$a->table_1_team === $team || (int)$a->table_2_team === $team;
                }
                return false;
            })->values();

            $merged = [];
            $i = 0;
            while ($i < $teamActivities->count()) {
                $current = $teamActivities[$i];
                if ($current->activity_atd === $rCheckId &&
                    $i + 1 < $teamActivities->count() &&
                    $teamActivities[$i + 1]->activity_atd === $rMatchId) {
                    $merged[] = (object) ['start' => $current->start, 'end' => $teamActivities[$i + 1]->end];
                    $i += 2;
                } else {
                    $merged[] = (object) ['start' => $current->start, 'end' => $current->end];
                    $i++;
                }
            }

            $d1 = array_values(array_filter($merged, fn($a) => str_starts_with($a->start, $day1Date)));
            $d2 = array_values(array_filter($merged, fn($a) => str_starts_with($a->start, $day2Date)));

            $gapsDay1 = [];
            for ($j = 1; $j < count($d1); $j++) {
                $prev = new \DateTime($d1[$j - 1]->end);
                $curr = new \DateTime($d1[$j]->start);
                $gapsDay1[] = ($curr->getTimestamp() - $prev->getTimestamp()) / 60;
            }

            $gapsDay2 = [];
            for ($j = 1; $j < count($d2); $j++) {
                $prev = new \DateTime($d2[$j - 1]->end);
                $curr = new \DateTime($d2[$j]->start);
                $gapsDay2[] = ($curr->getTimestamp() - $prev->getTimestamp()) / 60;
            }

            $allGaps = array_merge($gapsDay1, $gapsDay2);
            $q1Ok = !collect($allGaps)->contains(fn($g) => $g < $minGap);

            $summary[] = [
                'team' => $team,
                'q1_ok' => $q1Ok,
                'day1_1_2' => $gapsDay1[0] ?? 0,
                'day1_2_3' => $gapsDay1[1] ?? 0,
                'day2_1_2' => $gapsDay2[0] ?? 0,
                'day2_2_3' => $gapsDay2[1] ?? 0,
                'day2_3_4' => $gapsDay2[2] ?? 0,
            ];
        }

        return $summary;
    }
    
    public function startQRun(Request $request)
    {
        try {
            // Validate every selection key we persist — Laravel's validated()
            // payload only keeps nested keys that have rules (not the whole array).
            $payload = $request->validate([
                'name' => 'required|string|max:100',
                'comment' => 'nullable|string',
                'selection' => 'required|array',
                'selection.first_program' => 'required|integer|in:3,8',
                'selection.min_teams' => 'required|integer|min:4|max:25',
                'selection.max_teams' => 'required|integer|min:4|max:25|gte:selection.min_teams',
                'selection.jury_lanes' => 'required|array|min:1',
                'selection.jury_lanes.*' => 'integer|min:1|max:5',
                'selection.tables' => 'required|array|min:1',
                'selection.tables.*' => 'integer|in:2,4',
                'selection.jury_rounds' => 'required|array|min:1',
                'selection.jury_rounds.*' => 'integer|min:3|max:6',
                'selection.robot_check' => 'required|array|min:1',
                'selection.robot_check.*' => 'string|in:on,off',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            error_log('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        }

        $host = gethostname();
        $selection = $payload['selection'];
        $firstProgram = (int) $selection['first_program'];

        $qRunId = DB::table('q_run')->insertGetId([
            'name' => $payload['name'],
            'first_program' => $firstProgram,
            'comment' => $payload['comment'] ?? null,
            'selection' => json_encode($selection),
            'started_at' => Carbon::now(),
            'status' => 'pending',
            'host' => $host,
        ]);

        \App\Jobs\GenerateQPlansFromSelectionJob::dispatch($qRunId);

        Log::info("QualityController::startQRun", [
            'q_run' => $qRunId,
            'name' => $payload['name'],
            'first_program' => $firstProgram,
            'selection' => $selection,
        ]);

        return response()->json([
            'status' => 'queued',
            'run_id' => $qRunId,
        ]);
    }

    public function rerunQPlans(Request $request)
    {
        $planIds = $request->input('plan_ids');

        if (empty($planIds) || !is_array($planIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Keine QPlan-IDs übergeben.',
            ], 400);
        }

        $firstQPlan = DB::table('q_plan')->where('id', $planIds[0])->first();

        if (!$firstQPlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erster QPlan nicht gefunden.',
            ], 404);
        }

        $programIds = DB::table('q_plan')
            ->whereIn('id', $planIds)
            ->distinct()
            ->pluck('first_program');

        if ($programIds->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'ReRun mit gemischten first_program ist nicht erlaubt.',
            ], 400);
        }

        $originalRunId = $firstQPlan->q_run;
        $firstProgram = (int) ($programIds->first() ?? $firstQPlan->first_program ?? 3);
        $host = gethostname();

        $newRunId = DB::table('q_run')->insertGetId([
            'name' => "ReRun für $originalRunId (gefiltert)",
            'first_program' => $firstProgram,
            'comment' => null,
            'selection' => null,
            'started_at' => Carbon::now(),
            'status' => 'pending',
            'host' => $host,
        ]);

        \App\Jobs\GenerateQPlansFromQPlansJob::dispatch($newRunId, $planIds);

        Log::info("QualityController::rerunQPlans", [
            'new_q_run' => $newRunId,
            'original_q_run' => $originalRunId,
            'first_program' => $firstProgram,
            'plan_count' => count($planIds),
        ]);

        return response()->json([
            'status' => 'queued',
            'run_id' => $newRunId,
        ]);
    }

    public function listQRuns()
    {
        $qruns = QRun::orderBy('id', 'desc')->get();

        $hasRunning = $qruns->contains(function ($qrun) {
            return $qrun->status === 'running';
        });

        return response()->json([
            'qruns' => $qruns,
            'has_running' => $hasRunning,
        ]);
    }

    public function listQPlans(int $runId)
    {
        $plans = \App\Models\QPlan::where('q_run', $runId)
            ->where('calculated', 1)
            ->orderBy('c_teams')
            ->orderBy('j_lanes')
            ->orderBy('r_tables')
            ->orderBy('r_robot_check')
            ->get();

        return response()->json($plans);
    }

    public function getQPlanDetails(int $qplanId)
    {
        $teams = \App\Models\QPlanTeam::where('q_plan', $qplanId)->get();

        $qplan = \App\Models\QPlan::findOrFail($qplanId);
        $planId = $qplan->plan;
        $firstProgram = (int) ($qplan->first_program ?? FirstProgram::CHALLENGE->value);
        $isTwoDayEvent = (bool) (new PlanParameter($planId))->get('g_finale');

        $matches = \App\Models\MatchEntry::where('plan', $planId)
            ->where('first_program', $firstProgram)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        $c_teams = (int) $qplan->c_teams;
        $matchRows = $matches->map(static fn ($m) => [
            'round' => (int) $m->round,
            'match_no' => (int) $m->match_no,
            'table_1' => (int) $m->table_1,
            'table_2' => (int) $m->table_2,
            'table_1_team' => (int) $m->table_1_team,
            'table_2_team' => (int) $m->table_2_team,
        ])->all();
        $pairing = app(\App\Services\MatchPlanPairingQuality::class)->evaluate(
            $matchRows,
            $c_teams,
            (int) $qplan->r_tables
        );

        $matchPlanRounds = [];

        if ($isTwoDayEvent) {
            // Day 1 has two test rounds stored as activity groups, not in match.round=0.
            $testRoundGroupCode = $firstProgram === FirstProgram::FUTURE_8->value
                ? 'f8_test_round'
                : 'r_test_round';
            $matchCode = $firstProgram === FirstProgram::FUTURE_8->value
                ? 'f8_r_match'
                : 'r_match';

            $rTestRoundGroupAtdId = MActivityTypeDetail::where('code', $testRoundGroupCode)->value('id');
            $rMatchAtdId = MActivityTypeDetail::where('code', $matchCode)->value('id');

            $testRoundActivities = DB::table('activity as a')
                ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
                ->where('ag.plan', $planId)
                ->where('ag.activity_type_detail', $rTestRoundGroupAtdId)
                ->where('a.activity_type_detail', $rMatchAtdId)
                ->orderBy('a.start')
                ->orderBy('a.id')
                ->get([
                    'a.id',
                    'a.activity_group',
                    'a.start',
                    'a.table_1',
                    'a.table_1_team',
                    'a.table_2',
                    'a.table_2_team',
                ]);

            $groupedTestRounds = $testRoundActivities->groupBy('activity_group')->values();

            foreach ($groupedTestRounds as $idx => $groupMatches) {
                $roundNumber = $idx + 1;
                $matchPlanRounds[] = [
                    'key' => "tr{$roundNumber}",
                    'label' => "Testrunde {$roundNumber}",
                    'matches' => $groupMatches->values()->map(function ($m, $matchIdx) {
                        return [
                            'id' => $m->id,
                            'round' => null,
                            'match_no' => $matchIdx + 1,
                            'table_1' => $m->table_1,
                            'table_1_team' => $m->table_1_team,
                            'table_2' => $m->table_2,
                            'table_2_team' => $m->table_2_team,
                        ];
                    })->toArray(),
                ];
            }

            foreach ($pairing['scoring_rounds'] as $roundNum) {
                $roundMatches = $matches->where('round', $roundNum)->sortBy('match_no')->values();
                $matchPlanRounds[] = [
                    'key' => "r{$roundNum}",
                    'label' => "Runde {$roundNum}",
                    'matches' => $roundMatches->map(function ($m) {
                        return [
                            'id' => $m->id,
                            'round' => $m->round,
                            'match_no' => $m->match_no,
                            'table_1' => $m->table_1,
                            'table_1_team' => $m->table_1_team,
                            'table_2' => $m->table_2,
                            'table_2_team' => $m->table_2_team,
                        ];
                    })->toArray(),
                ];
            }
        } else {
            $roundNums = $matches->pluck('round')->unique()->sort()->values();
            foreach ($roundNums as $roundNum) {
                $roundMatches = $matches->where('round', $roundNum)->sortBy('match_no')->values();
                $matchPlanRounds[] = [
                    'key' => (string) $roundNum,
                    'label' => $roundNum === 0 ? 'Testrunde' : "Runde {$roundNum}",
                    'matches' => $roundMatches->map(function ($m) {
                        return [
                            'id' => $m->id,
                            'round' => $m->round,
                            'match_no' => $m->match_no,
                            'table_1' => $m->table_1,
                            'table_1_team' => $m->table_1_team,
                            'table_2' => $m->table_2,
                            'table_2_team' => $m->table_2_team,
                        ];
                    })->toArray(),
                ];
            }
        }

        $transferSummary = $isTwoDayEvent
            ? $this->buildTwoDayTransferSummary($planId, $c_teams, (int) $qplan->c_duration_transfer)
            : [];

        return response()->json([
            'first_program' => $firstProgram,
            'teams' => $teams,
            'matches' => $matches,
            'match_plan_rounds' => $matchPlanRounds,
            'is_two_day_event' => $isTwoDayEvent,
            'transfer_summary' => $transferSummary,
            'c_duration_transfer' => (int) $qplan->c_duration_transfer,
            'r_tables' => (int) $qplan->r_tables,
            'scoring_rounds' => $pairing['scoring_rounds'],
            'match_summary' => $pairing['match_summary'],
        ]);
    }

    /**
     * Ensure a QPlan exists and is up-to-date for a given plan ID, then return details.
     * Optional query first_program (3|8) selects which Challenge-shaped program to evaluate.
     */
    public function getQPlanDetailsByPlan(Request $request, int $planId)
    {
        $plan = DB::table('plan')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'Plan not found'], 404);
        }

        $pp = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $pp);
        $onPrograms = $presence->challengeShapedOnIds();

        $requested = $request->query('first_program');
        if ($requested !== null && $requested !== '') {
            $firstProgram = (int) $requested;
            if (! ChallengeShapedParamMap::isSupported($firstProgram)) {
                return response()->json(['message' => 'first_program must be Challenge or Future 8+'], 422);
            }
            if ($onPrograms !== [] && ! in_array($firstProgram, $onPrograms, true)) {
                return response()->json([
                    'message' => 'first_program is not on for this plan',
                    'programs' => $onPrograms,
                ], 422);
            }
        } else {
            $firstProgram = $presence->leadProgramId() ?? FirstProgram::CHALLENGE->value;
            if (! ChallengeShapedParamMap::isSupported($firstProgram)) {
                $firstProgram = FirstProgram::CHALLENGE->value;
            }
        }

        $evaluator = app(\App\Services\QualityEvaluatorService::class);
        $qPlan = $evaluator->ensureEvaluatedForPlan($planId, $firstProgram);

        return $this->getQPlanDetails($qPlan->id);
    }

    public function deleteQRun(int $qRunId)
    {
        try {
            $qRun = DB::table('q_run')->where('id', $qRunId)->first();
            if (! $qRun) {
                Log::warning("qRun $qRunId: not found");
                return response()->json(['status' => 'not_found'], 404);
            }

            // Mass-test runs own disposable plans. Preview/ReRun rows
            // (selection null) may point at a real event plan — never delete those.
            $deleteOwnedPlans = $qRun->selection !== null;

            $planIds = [];
            if ($deleteOwnedPlans) {
                $planIds = DB::table('q_plan')
                    ->where('q_run', $qRunId)
                    ->whereNotNull('plan')
                    ->pluck('plan')
                    ->unique()
                    ->all();
            }

            // Delete the q_run - CASCADE DELETE will handle all related records:
            // q_run -> q_plan -> q_plan_team, q_plan_match
            DB::table('q_run')->where('id', $qRunId)->delete();

            if ($deleteOwnedPlans && ! empty($planIds)) {
                DB::table('plan')->whereIn('id', $planIds)->delete();
            }

            Log::info('QualityController::deleteQRun', [
                'q_run' => $qRunId,
                'plans_deleted' => count($planIds),
                'owned_plans' => $deleteOwnedPlans,
            ]);

            return response()->json(['status' => 'deleted']);
        } catch (\Exception $e) {
            Log::error("deleteQRun($qRunId) failed: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete Preview / ReRun quality runs (selection IS NULL).
     * Does not delete plan rows — Preview q_plans reference real event plans.
     */
    public function deletePreviewQRuns()
    {
        try {
            $runIds = DB::table('q_run')
                ->whereNull('selection')
                ->pluck('id')
                ->all();

            if ($runIds === []) {
                return response()->json([
                    'status' => 'deleted',
                    'q_runs_deleted' => 0,
                ]);
            }

            $deleted = DB::table('q_run')->whereIn('id', $runIds)->delete();

            Log::info('QualityController::deletePreviewQRuns', [
                'q_runs_deleted' => $deleted,
                'q_run_ids' => $runIds,
            ]);

            return response()->json([
                'status' => 'deleted',
                'q_runs_deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            Log::error('deletePreviewQRuns failed: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // compressQRun removed – functionality no longer needed


}
