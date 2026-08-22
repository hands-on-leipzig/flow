<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\AfternoonBlockOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfternoonController extends Controller
{
    public function __construct(private AfternoonBlockOrderService $afternoonBlocks)
    {
    }

    public function blocks(int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        return response()->json([
            'blocks' => $this->afternoonBlocks->resolvedBlocks($planId)->values(),
        ]);
    }

    public function updateOrder(Request $request, int $planId): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $blocks = $this->afternoonBlocks->saveOrder($planId, $data['ids']);

        return response()->json(['blocks' => $blocks->values()]);
    }
}
