<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvaluateQuality;
use App\Jobs\ExecuteQRun;
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
