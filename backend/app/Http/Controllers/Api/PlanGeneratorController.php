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
            $supportCheck = $this->generator->isSupported($planId);
            if (! $supportCheck['supported']) {
                Log::warning("Plan {$planId}: Unsupported plan parameters", $supportCheck);
                return response()->json([
                    'error' => $supportCheck['error'] ?? "Plan {$planId} wird nicht unterstützt",
                    'details' => $supportCheck['details'] ?? null,
                ], 422);
            }

            // Vorbereitung mit korrektem Mode
            $mode = $async ? 'job' : 'direct';
            $this->generator->prepare($planId, $mode);

            if ($async) {
                $this->generator->dispatchJob($planId);
                Log::info('Generation dispatched', ['plan_id' => $planId]);
                return response()->json(['message' => 'Generation dispatched'], 202);
            }

            // Direkte Ausführung
            Log::info('Generation started', ['plan_id' => $planId]);
            try {
                $this->generator->run($planId);
                return response()->json(['message' => 'Generation done']);
            } catch (\Throwable $e) {
                // Re-throw to be caught by outer catch block for proper error formatting
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Generation failed', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Extract parameter details from RuntimeException messages if available
            $errorMessage = 'Fehler bei der Plan-Generierung';
            $details = $e->getMessage();
            
            // Check if it's a parameter validation error
            if (str_contains($e->getMessage(), "Parameter '")) {
                $errorMessage = 'Ungültiger Parameterwert';
                // The RuntimeException already contains detailed info about parameter name and value
            } elseif (str_contains($e->getMessage(), "not found")) {
                $errorMessage = 'Fehlende Daten';
            }
            
            return response()->json([
                'error' => $errorMessage,
                'details' => $details,
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
            'status'  => $status->value,
        ]);
    }

    public function generateLite(int $planId): JsonResponse
    {
        // Existiert der Plan?
        if ($response = $this->ensurePlanExists($planId)) {
            return $response;
        }

        try {
            // Service aufrufen
            $this->generator->generateLite($planId);

            return response()->json([
                'status' => 'ok',
                'message' => "Lite generation completed for plan {$planId}",
            ]);
        } catch (\Throwable $e) {
            Log::error('Lite generation failed', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Extract meaningful error message from exception
            $errorMessage = 'Fehler bei der Lite-Generierung';
            $details = $e->getMessage();
            
            // Check if it's a parameter validation error
            if (str_contains($e->getMessage(), "Parameter '")) {
                $errorMessage = 'Ungültiger Parameterwert';
                // The RuntimeException already contains detailed info about parameter name and value
            } elseif (str_contains($e->getMessage(), "not found")) {
                $errorMessage = 'Fehlende Daten';
            } elseif (str_contains($e->getMessage(), "FreeBlockGenerator") || str_contains($e->getMessage(), "free block")) {
                $errorMessage = 'Fehler beim Einfügen der freien Blöcke';
            }
            
            return response()->json([
                'error' => $errorMessage,
                'details' => $details,
            ], 500);
        }
    }

    private function ensurePlanExists(int $planId): ?JsonResponse
    {
        $exists = DB::table('plan')->where('id', $planId)->exists();
        if (! $exists) {
            Log::warning('Plan not found', ['plan_id' => $planId]);
            return response()->json([
                'error' => "Plan {$planId} nicht gefunden",
            ], 404);
        }

        return null;
    }
}