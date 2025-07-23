<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function getPlansByEvent($eventId): JsonResponse
    {
        $plans = DB::table('plan')
            ->where('event', $eventId)
            ->select('id', 'name') // Add more fields if needed
            ->orderBy('name')
            ->get();

        return response()->json($plans);
    }

    public function create(Request $request): JsonResponse
    {
        Log::debug($request->all());
        $validated = $request->validate([
            'event' => 'required|exists:event,id',
            'name' => 'required|string|max:255',
        ]);
        $validated['created'] = Carbon::now();
        $validated['last_change'] = Carbon::now();
        $plan = Plan::create($validated);

        return response()->json($plan, 201);
    }
}
