<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\RoomTypeFetcher;

class PlanRoomTypeController extends Controller
{
    protected RoomTypeFetcher $fetcher;

    public function __construct(RoomTypeFetcher $fetcher)
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