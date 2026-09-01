<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\CeremonyTimesService;
use Illuminate\Http\JsonResponse;

class PlanCeremonyTimesController extends Controller
{
    public function __construct(private CeremonyTimesService $ceremonyTimes)
    {
    }

    public function show(int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        return response()->json($this->ceremonyTimes->forPlan($planId));
    }
}
