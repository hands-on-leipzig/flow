<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteQRun;
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
        // TODO: Implement run listing logic
        return response()->json(['status' => 'not implemented'], 501);
    }

    public function listQPlans(int $runId)
    {
        // TODO: Implement plan retrieval for given run
        return response()->json(['status' => 'not implemented'], 501);
    }

    public function getQPlanDetails(int $planId)
    {
        // TODO: Implement plan details retrieval
        return response()->json(['status' => 'not implemented'], 501);
    }
}
