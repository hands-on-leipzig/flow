<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\PlanGeneratorService;

class PlanGeneratorController extends Controller
{
    protected PlanGeneratorService $generator;

    public function __construct(PlanGeneratorService $generator)
    {
        $this->generator = $generator;
    }

    public function generate(int $planId, bool $async = false): JsonResponse
    {
        // Prüfen, ob Plan unterstützt wird
        if (! $this->generator->isSupported($planId)) {
            Log::warning("Plan {$planId}: Unsupported plan parameters");
            return response()->json([
                'error' => "Plan {$planId} not supported",
            ], 400);
        }

        // Vorbereitung
        $this->generator->prepare($planId);

        if ($async) {
            Log::info("Plan {$planId}: Generation dispatched");
            $this->generator->dispatchJob($planId);

            return response()->json(['message' => 'Generation dispatched']);
        }

        // Direkte Ausführung
        Log::info("Plan {$planId}: Generation started");
        $this->generator->run($planId);

        return response()->json(['message' => 'Generation done']);
    }

    public function status(int $planId): JsonResponse
    {
        $status = $this->generator->status($planId);

        return response()->json([
            'plan_id' => $planId,
            'status'  => $status,
        ]);
    }

    public function generateLite(int $planId)
    {
        // Service aufrufen
        app(\App\Services\PlanGeneratorService::class)->generateLite($planId);

        return response()->json([
            'status' => 'ok',
            'message' => "Lite generation completed for plan {$planId}",
        ]);
    }
}