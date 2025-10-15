<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\PlanGeneratorService;

class PlanGeneratorController extends Controller
{
    protected PlanGeneratorService $generator;

    public function __construct(PlanGeneratorService $generator)
    {
        $this->generator = $generator;
    }

    public function generate(int $planId, Request $request): JsonResponse
    {
        $async = $request->boolean('async');
        
        // Existiert der Plan?
        if ($response = $this->ensurePlanExists($planId)) {
            return $response;
        }

        try {
            // Prüfen, ob Plan unterstützt wird
            if (! $this->generator->isSupported($planId)) {
                Log::warning("Plan {$planId}: Unsupported plan parameters");
                return response()->json([
                    'error' => "Plan {$planId} not supported",
                ], 422);
            }

            // Vorbereitung
            $this->generator->prepare($planId);

            if ($async) {
                $this->generator->dispatchJob($planId);
                Log::info('Generation dispatched', ['plan_id' => $planId]);
                return response()->json(['message' => 'Generation dispatched'], 202);
            }

            // Direkte Ausführung
            Log::info('Generation started', ['plan_id' => $planId]);
            $this->generator->run($planId);

            return response()->json(['message' => 'Generation done']);
        } catch (\Throwable $e) {
            Log::error('Generation failed', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Generation failed',
            ], 500);
        }
    }

    public function status(int $planId): JsonResponse
    {
        // Existiert der Plan?
        if ($response = $this->ensurePlanExists($planId)) {
            return $response;
        }

        $status = $this->generator->status($planId);

        return response()->json([
            'plan_id' => $planId,
            'status'  => $status,
        ]);
    }

    public function generateLite(int $planId): JsonResponse
    {
        // Existiert der Plan?
        if ($response = $this->ensurePlanExists($planId)) {
            return $response;
        }

        // Service aufrufen
        $this->generator->generateLite($planId);

        return response()->json([
            'status' => 'ok',
            'message' => "Lite generation completed for plan {$planId}",
        ]);
    }

    private function ensurePlanExists(int $planId): ?JsonResponse
    {
        $exists = DB::table('plan')->where('id', $planId)->exists();
        if (! $exists) {
            Log::warning('Plan not found', ['plan_id' => $planId]);
            return response()->json([
                'error' => "Plan {$planId} not found",
            ], 404);
        }

        return null;
    }
}