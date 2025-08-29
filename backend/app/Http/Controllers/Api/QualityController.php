<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteQRun;
use App\Models\QRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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

        $runId = DB::table('q_run')->insertGetId([
            'name' => $payload['name'],
            'comment' => $payload['comment'] ?? null,
            'selection' => json_encode($payload['selection']),
            'started_at' => now(),
            'status' => 'pending',
        ]);

        // Job dispatchen (asynchron)
        ExecuteQRun::dispatch($runId);

        return response()->json([
            'status' => 'started',
            'run_id' => $runId,
        ]);
    }

    public function listQRuns()
    {
        $runs = QRun::orderBy('id', 'desc')->get();

        $hasRunning = $runs->contains(function ($run) {
            return $run->status === 'running';
        });

        return response()->json([
            'runs' => $runs,
            'has_running' => $hasRunning,
        ]);
    }

    public function listQPlans(int $runId)
    {
        $plans = \App\Models\QPlan::where('q_run', $runId)
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
}
