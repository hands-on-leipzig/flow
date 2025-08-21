<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExtraBlock;
use App\Models\MInsertPoint;
use Illuminate\Http\Request;

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

    public function storeOrUpdate(Request $request, int $planId)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:extra_block,id',
            'first_program' => 'nullable|integer|in:0,2,3',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:255',
            'insert_point' => 'nullable|integer|exists:m_insert_point,id',
            'buffer_before' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
            'buffer_after' => 'nullable|integer|min:0',
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'room' => 'nullable|integer|exists:room,id',
        ]);

        // Ensure plan is set from route param
        $validated['plan'] = $planId;

        // Update if ID present, otherwise create
        $block = ExtraBlock::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            $validated
        );

        return response()->json($block);
    }

    public function delete(int $id)
    {
        $deleted = ExtraBlock::destroy($id);
        return response()->json($deleted);
    }
}
