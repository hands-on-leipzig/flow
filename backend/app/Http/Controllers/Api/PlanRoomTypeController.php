<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\RoomTypeFetcherService;

class PlanRoomTypeController extends Controller
{
    protected RoomTypeFetcherService $fetcher;

    public function __construct(RoomTypeFetcherService $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * Liste aller Room Types für einen Plan (inkl. Extra Blocks)
     */
    public function listRoomTypes(int $planId): JsonResponse
    {
        $roomTypes = $this->fetcher->fetchRoomTypes($planId);
        return response()->json($roomTypes);
    }
}