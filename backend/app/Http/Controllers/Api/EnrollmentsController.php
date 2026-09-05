<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EnrollmentsService;
use App\Services\SeasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentsController extends Controller
{
    public function __construct(
        private readonly EnrollmentsService $enrollments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        set_time_limit(0);

        $seasonId = $request->query('season') !== null
            ? (int) $request->query('season')
            : SeasonService::currentSeasonId();

        return response()->json($this->enrollments->forSeason($seasonId));
    }
}
