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

        Log::info("qRun $qRunId: generation of qPlans from selection dispatched");

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

        Log::info("qRun $newRunId: copying of qPlans dispatched");

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
        $matches = \App\Models\QPlanMatch::where('q_plan', $qplanId)->get();

        $qplan = \App\Models\QPlan::findOrFail($qplanId);
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

    public function deleteQRun(int $qRunId)
    {
        // 1. Alle Plan-IDs finden, die zu den qPlans unter diesem qRun gehören
        $planIds = DB::table('q_plan')
            ->where('q_run', $qRunId)
            ->whereNotNull('plan')
            ->pluck('plan')
            ->unique()
            ->all();

        // 2. Zuerst die Pläne löschen (die löschen durch FK ihre abhängigen Daten mit)
        if (!empty($planIds)) {
            DB::table('plan')->whereIn('id', $planIds)->delete();
            Log::info("qRun $qRunId: Plans deleted " . implode(',', $planIds));
        }

        // 3. Danach den qRun löschen (dies löscht auch alle qPlans + Matches + Teams über FK)
        $deleted = DB::table('q_run')->where('id', $qRunId)->delete();

        if ($deleted) {
            Log::info("qRun $qRunId: deleted");
            return response()->json(['status' => 'deleted']);
        } else {
            Log::warning("qRun $qRunId: not found");
            return response()->json(['status' => 'not_found'], 404);
        }
    }


    public function compressQRun(int $qRunId)
    {
        try {
            // 1. Alle Plan-IDs holen
            $planIds = DB::table('q_plan')
                ->where('q_run', $qRunId)
                ->whereNotNull('plan')
                ->pluck('plan')
                ->unique();

            // 2. Pläne löschen
            if ($planIds->isNotEmpty()) {
                DB::table('plan')->whereIn('id', $planIds)->delete();
                Log::info("qRun $qRunId: plans deleted " . implode(',', $planIds->toArray()));
            }

            // 3. q_plan.plan auf NULL setzen
            DB::table('q_plan')
                ->where('q_run', $qRunId)
                ->update(['plan' => null]);

            // 4. Status vom q_run auf archived setzen
            DB::table('q_run')
                ->where('id', $qRunId)
                ->update(['status' => 'compressed']);

            Log::info("qRun $qRunId: compressed.");

            return response()->json(['status' => 'compressed']);
        } catch (\Exception $e) {
            Log::error("compressQRun($qRunId) failed: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


}
