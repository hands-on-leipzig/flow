<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Plan;
use App\Jobs\GeneratePlanJob;
use App\Services\GeneratePlan;

class PlanGenerateController extends Controller
{
    public function generate($planId, $async = false): JsonResponse
    {

        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $plan->generator_status = 'running';
        $plan->save();

        // Note the start
        DB::table('s_generator')->insertGetId([
            'plan' => $planId,
            'start' => \Carbon\Carbon::now(),
            'mode' => $async ? 'job' : 'direct',
        ]);


        if ($async) {

            log::info("Plan {$planId}: Generation dispatched");

            GeneratePlanJob::dispatch($planId);

            return response()->json(['message' => 'Generation dispatched']);

        } else {

            log::info("Plan {$planId}: Generation started");

            GeneratePlan::run($plan->id);

            $plan->generator_status = 'done';
            $plan->save();

            return response()->json(['message' => 'Generation done']);
        }

    }

    public function status($planId): JsonResponse
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        return response()->json(['status' => $plan->generator_status]);
    }
}