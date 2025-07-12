<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
