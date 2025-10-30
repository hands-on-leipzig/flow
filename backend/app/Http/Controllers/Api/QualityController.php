<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\QRun;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class QualityController extends Controller
{
    
    public function startQRun(Request $request)
    {
        try {
            $payload = $request->validate([
                'name' => 'required|string|max:100',
                'comment' => 'nullable|string',
                'selection' => 'required|array',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            error_log('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        }

        $host = gethostname();

        $qRunId = DB::table('q_run')->insertGetId([
            'name' => $payload['name'],
            'comment' => $payload['comment'] ?? null,
            'selection' => json_encode($payload['selection']),
            'started_at' => Carbon::now(),
            'status' => 'pending',
            'host' => $host,
        ]);

        \App\Jobs\GenerateQPlansFromSelectionJob::dispatch($qRunId);

        Log::info("QualityController::startQRun", [
            'q_run' => $qRunId,
            'name' => $payload['name'],
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

        $originalRunId = $firstQPlan->q_run;
        $host = gethostname();

        $newRunId = DB::table('q_run')->insertGetId([
            'name' => "ReRun für $originalRunId (gefiltert)",
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
        
        // Get plan ID from q_plan and fetch matches from match table
        $qplan = \App\Models\QPlan::findOrFail($qplanId);
        $planId = $qplan->plan;
        $matches = \App\Models\MatchEntry::where('plan', $planId)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        $c_teams = $qplan->c_teams;

        // Indexiere Matches nach Runde für schnelleren Zugriff
        $matchesByRound = $matches->groupBy('round');

        $summary = [];

        for ($team = 1; $team <= $c_teams; $team++) {
            $entry = ['team' => $team];

            // Runde 0 – Testrunde
            $round0 = $matchesByRound[0]->first(fn($m) => $m->table_1_team == $team || $m->table_2_team == $team);
            $entry['tr_table'] = $round0?->table_1_team == $team ? $round0->table_1 : $round0?->table_2;

            // Runde 1–3
            $tables = [];
            $opponents = [];

            foreach ([1, 2, 3] as $r) {
                $match = $matchesByRound[$r]?->first(fn($m) => $m->table_1_team == $team || $m->table_2_team == $team);
                if ($match) {
                    $tableKey = "r{$r}_table";
                    $oppKey = "r{$r}_opponent";

                    $table = $match->table_1_team == $team ? $match->table_1 : $match->table_2;
                    $opponent = $match->table_1_team == $team ? $match->table_2_team : $match->table_1_team;

                    $entry[$tableKey] = $table;
                    $entry[$oppKey] = $opponent;

                    $tables[] = $table;
                    $opponents[] = $opponent;
                } else {
                    $entry["r{$r}_table"] = null;
                    $entry["r{$r}_opponent"] = null;
                }
            }

            $entry['tables'] = count(array_unique($tables));
            $entry['teams'] = count(array_unique($opponents));

            $summary[] = $entry;
        }

        return response()->json([
            'teams' => $teams,
            'matches' => $matches,
            'c_duration_transfer' => (int) $qplan->c_duration_transfer,
            'r_tables' => (int) $qplan->r_tables,
            'match_summary' => $summary,
        ]);
    }

    /**
     * Ensure a QPlan exists and is up-to-date for a given plan ID, then return details.
     */
    public function getQPlanDetailsByPlan(int $planId)
    {
        // Load plan.last_change
        $plan = DB::table('plan')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'Plan not found'], 404);
        }

        $qplan = DB::table('q_plan')->where('plan', $planId)->first();

        $needsCreateOrRefresh = false;
        if (!$qplan) {
            $needsCreateOrRefresh = true;
        } else {
            // If q_plan.last_change is null or older than plan.last_change, refresh
            if (!empty($plan->last_change)) {
                $planChanged = \Carbon\Carbon::parse($plan->last_change);
                $qLast = $qplan->last_change ? \Carbon\Carbon::parse($qplan->last_change) : null;
                if ($qLast === null || $qLast->lt($planChanged)) {
                    $needsCreateOrRefresh = true;
                }
            }
        }

        if ($needsCreateOrRefresh) {
            // Create a minimal q_run and q_plan, then evaluate
            $host = gethostname();
            $runId = DB::table('q_run')->insertGetId([
                'name' => "Auto für Plan {$planId}",
                'comment' => 'Automatisch erstellt durch Preview',
                'selection' => null,
                'started_at' => \Carbon\Carbon::now(),
                'status' => 'running',
                'host' => $host,
            ]);

            // Load parameters
            $pp = new \App\Support\PlanParameter($planId);
            $cTeams = (int) $pp->get('c_teams');
            $rTables = (int) $pp->get('r_tables');
            $jLanes = (int) $pp->get('j_lanes');
            $juryRounds = (int) ceil(max(1, $cTeams) / max(1, $jLanes));
            $robotCheck = (bool) $pp->get('r_robot_check');
            $rDurationRobotCheck = (int) $pp->get('r_duration_robot_check');
            $cDurationTransfer = (int) $pp->get('c_duration_transfer');
            $rAsym = ($rTables === 4 && ($cTeams % 4 === 1 || $cTeams % 4 === 2)) ? 1 : 0;

            $qPlanId = DB::table('q_plan')->insertGetId([
                'plan' => $planId,
                'q_run' => $runId,
                'name' => $plan->name,
                'c_teams' => $cTeams,
                'r_tables' => $rTables,
                'j_lanes' => $jLanes,
                'j_rounds' => $juryRounds,
                'r_asym' => $rAsym,
                'r_robot_check' => $robotCheck,
                'r_duration_robot_check' => $rDurationRobotCheck,
                'c_duration_transfer' => $cDurationTransfer,
                'calculated' => false,
                'last_change' => null,
            ]);

            // Evaluate to populate q_plan_team and summary fields
            app(\App\Services\QualityEvaluatorService::class)->evaluate($qPlanId);

            // Mark run as done and update counters
            $totals = DB::table('q_plan')->where('q_run', $runId)->count();
            $calculated = DB::table('q_plan')->where('q_run', $runId)->where('calculated', 1)->count();
            DB::table('q_run')->where('id', $runId)->update([
                'qplans_total' => $totals,
                'qplans_calculated' => $calculated,
                'finished_at' => \Carbon\Carbon::now(),
                'status' => 'done',
            ]);

            $qplan = DB::table('q_plan')->where('id', $qPlanId)->first();

            // Cleanup: remove any older q_plan versions for this plan (keep only the fresh one)
            DB::table('q_plan')
                ->where('plan', $planId)
                ->where('id', '!=', $qPlanId)
                ->delete();
        }

        // Reuse details builder
        return $this->getQPlanDetails($qplan->id);
    }

    public function deleteQRun(int $qRunId)
    {
        try {
            // Find plan IDs that will be deleted (for logging)
            $planIds = DB::table('q_plan')
                ->where('q_run', $qRunId)
                ->whereNotNull('plan')
                ->pluck('plan')
                ->unique()
                ->all();

            // Delete the q_run - CASCADE DELETE will handle all related records:
            // q_run -> q_plan -> q_plan_team, q_plan_match
            $deleted = DB::table('q_run')->where('id', $qRunId)->delete();

            if ($deleted) {
                // Also delete the plan records (they're not CASCADE deleted)
                if (!empty($planIds)) {
                    DB::table('plan')->whereIn('id', $planIds)->delete();
                }
                
                Log::info("QualityController::deleteQRun", [
                    'q_run' => $qRunId,
                    'plans_deleted' => count($planIds),
                ]);
                return response()->json(['status' => 'deleted']);
            } else {
                Log::warning("qRun $qRunId: not found");
                return response()->json(['status' => 'not_found'], 404);
            }
        } catch (\Exception $e) {
            Log::error("deleteQRun($qRunId) failed: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    // compressQRun removed – functionality no longer needed


}
