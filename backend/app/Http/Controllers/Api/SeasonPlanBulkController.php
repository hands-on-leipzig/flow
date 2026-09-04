<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeasonPlanBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SeasonPlanBulkController extends Controller
{
    public function __construct(
        private readonly SeasonPlanBulkService $bulk,
    ) {}

    public function summary(): JsonResponse
    {
        try {
            return response()->json($this->bulk->summary());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function empty(): JsonResponse
    {
        set_time_limit(0);

        try {
            return response()->json($this->bulk->empty());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function regenerate(Request $request): JsonResponse
    {
        set_time_limit(0);

        try {
            return response()->json($this->bulk->regenerate($request->user()?->id));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }
}
