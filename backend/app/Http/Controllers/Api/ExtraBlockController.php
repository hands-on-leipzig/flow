<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExtraBlock;
use App\Models\MInsertPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Api\PlanGeneratorController;

class ExtraBlockController extends Controller
{
    public function getInsertPoints()
    {
        $insert_points = MInsertPoint::all();
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
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:extra_block,id',
            'first_program' => 'nullable|integer|in:0,2,3',
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
                $generator->generateLite($planId);
            } catch (\Throwable $e) {
                Log::error("Fehler bei der Regeneration des Plans {$planId}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
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
