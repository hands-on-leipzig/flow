<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvaluateQuality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualityController extends Controller
{
    public function debug(int $planId)
    {
        error_log("debug called");

        $service = new EvaluateQuality();
        $data = $service->debugDump($planId);
        return response()->json($data);
    }

    public function startRun(Request $request)
    {
        error_log('startRun top'); // <-- ganz oben!

            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:100',
                    'comment' => 'nullable|string',
                    'min_teams' => 'required|integer|min:4|max:25',
                    'max_teams' => 'required|integer|min:4|max:25|gte:min_teams',
                    'lane_1' => 'nullable|boolean',
                    'lane_2' => 'nullable|boolean',
                    'lane_3' => 'nullable|boolean',
                    'lane_4' => 'nullable|boolean',
                    'lane_5' => 'nullable|boolean',
                    'tables_2' => 'nullable|boolean',
                    'tables_4' => 'nullable|boolean',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                error_log('Validation failed: ' . json_encode($e->errors()));
                return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
            }

            error_log('Validation passed');

        $runId = DB::table('q_run')->insertGetId([
            'input_json' => json_encode($validated),
            'name' => $validated['name'],
            'comment' => $validated['comment'] ?? null,
            'started_at' => now(),
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'started',
            'run_id' => $runId,
        ]);
    }


    public function listRuns()
    {
        // TODO: Implement run listing logic
        return response()->json(['status' => 'not implemented'], 501);
    }

    public function getRunPlans(int $runId)
    {
        // TODO: Implement plan retrieval for given run
        return response()->json(['status' => 'not implemented'], 501);
    }

    public function getPlanDetails(int $planId)
    {
        // TODO: Implement plan details retrieval
        return response()->json(['status' => 'not implemented'], 501);
    }
}
