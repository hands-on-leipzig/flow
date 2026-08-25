<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPlanController extends Controller
{
    public function __construct(private PublicPlanService $publicPlan) {}

    /**
     * Public role picker for interactive visitor schedule.
     */
    public function roles(int $planId): JsonResponse
    {
        return response()->json($this->publicPlan->getRoles($planId));
    }

    /**
     * Public role-filtered schedule.
     *
     * Query: role, team, lane, table, expired (yes|no), now (Y-m-d H:i)
     */
    public function schedule(int $planId, Request $request): JsonResponse
    {
        return response()->json($this->publicPlan->getSchedule($planId, $request->query()));
    }
}
