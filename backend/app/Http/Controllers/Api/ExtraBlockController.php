<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExtraBlock;
use App\Models\MInsertPoint;
use App\Enums\FirstProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Api\PlanGeneratorController;

class ExtraBlockController extends Controller
{
    public function getInsertPoints(Request $request)
    {
        $eventLevel = $request->query('level');
        
        // If event level is provided, filter insert points by level
        if ($eventLevel !== null) {
            $insert_points = MInsertPoint::where('level', '<=', $eventLevel)->get();
        } else {
            // No level provided, return all insert points
            $insert_points = MInsertPoint::all();
        }
        
        return response()->json($insert_points);
    }

    public function getBlocksForPlan(int $planId)
    {
        $blocks = ExtraBlock::query()
            ->where('plan', $planId)
            ->orderBy('insert_point')
            ->orderBy('start')
            ->get();

        return response()->json($blocks);
    }

    public function getBlocksForPlanWithRoomTypes(int $planId)
    {
        $blocks = ExtraBlock::query()
            ->with(['insertPoint.roomType'])
            ->where('plan', $planId)
            ->orderBy('insert_point')
            ->orderBy('start')
            ->get();

        return response()->json($blocks);
    }

    public function storeOrUpdate(Request $request, int $planId)
    {
        $allowedPrograms = implode(',', [
            FirstProgram::JOINT->value,
            FirstProgram::DISCOVER->value,
            FirstProgram::EXPLORE->value,
            FirstProgram::CHALLENGE->value
        ]);
        
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:extra_block,id',
            'first_program' => "nullable|integer|in:{$allowedPrograms}",
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:255',
            'insert_point' => 'nullable|integer|exists:m_insert_point,id',
            'buffer_before' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
            'buffer_after' => 'nullable|integer|min:0',
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'room' => 'nullable|integer|exists:room,id',
            'active' => 'nullable|boolean',
            'skip_regeneration' => 'nullable|boolean', // New flag to skip regeneration
        ]);

        // Ensure plan is set from route param
        $validated['plan'] = $planId;

        // Check if this is a timing-related update
        $skipRegeneration = $validated['skip_regeneration'] ?? false;
        unset($validated['skip_regeneration']); // Remove from data before saving

        // Update if ID present, otherwise create
        $block = ExtraBlock::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            $validated
        );

        if (!$skipRegeneration) {
            try {
                $generator = app(PlanGeneratorController::class);
                $response = $generator->generateLite($planId);
                
                // Check if the response indicates an error
                if ($response->getStatusCode() !== 200) {
                    $responseData = $response->getData(true);
                    Log::error("Fehler bei der Lite-Regeneration des Plans {$planId}", [
                        'status' => $response->getStatusCode(),
                        'error' => $responseData['error'] ?? 'Unknown error',
                        'details' => $responseData['details'] ?? null,
                    ]);
                    // Return error response to frontend
                    return response()->json([
                        'block' => $block,
                        'skip_regeneration' => $skipRegeneration,
                        'error' => $responseData['error'] ?? 'Fehler bei der Lite-Generierung',
                        'details' => $responseData['details'] ?? $responseData['message'] ?? null,
                    ], $response->getStatusCode());
                }
            } catch (\Throwable $e) {
                Log::error("Fehler bei der Regeneration des Plans {$planId}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Extract meaningful error message
                $errorMessage = 'Fehler bei der Lite-Generierung';
                $details = $e->getMessage();
                
                if (str_contains($e->getMessage(), "Parameter '")) {
                    $errorMessage = 'Ungültiger Parameterwert';
                } elseif (str_contains($e->getMessage(), "not found") || str_contains($e->getMessage(), "existiert nicht")) {
                    $errorMessage = 'Fehlende Daten';
                } elseif (str_contains($e->getMessage(), "FreeBlockGenerator") || str_contains($e->getMessage(), "freien Aktivitäten")) {
                    $errorMessage = 'Fehler beim Einfügen der freien Blöcke';
                }
                
                // Return error response to frontend
                return response()->json([
                    'block' => $block,
                    'skip_regeneration' => $skipRegeneration,
                    'error' => $errorMessage,
                    'details' => $details,
                ], 500);
            }
        }

        // Return the block with regeneration flag
        return response()->json([
            'block' => $block,
            'skip_regeneration' => $skipRegeneration
        ]);
    }

    public function delete(int $id)
    {
        $block = ExtraBlock::findOrFail($id);

        DB::table('activity')
            ->where('extra_block', $block->id)
            ->update(['extra_block' => null]);

        $block->delete();

        return response()->json(['message' => 'Extra block deleted']);
    }
}
