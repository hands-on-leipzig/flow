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
     * Query: role, team, lane, table, expired (yes|no), now (Y-m-d H:i).
     * Without now, expired filtering uses Berlin wall clock on the event day.
     */
    public function schedule(int $planId, Request $request): JsonResponse
    {
        return response()->json($this->publicPlan->getSchedule($planId, $request->query()));
    }

    /**
     * First with-team meetings on a jury lane (visitor jury-group overview).
     *
     * Query: program (first_program id), lane.
     */
    public function laneMeetings(int $planId, Request $request): JsonResponse
    {
        $program = (int) ($request->query('program') ?? 0);
        $lane = (int) ($request->query('lane') ?? 0);

        return response()->json($this->publicPlan->getLaneMeetings($planId, $program, $lane));
    }
}
