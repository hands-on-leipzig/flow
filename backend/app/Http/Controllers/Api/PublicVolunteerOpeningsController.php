<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicVolunteerOpeningsService;
use Illuminate\Http\JsonResponse;

class PublicVolunteerOpeningsController extends Controller
{
    public function __construct(
        private PublicVolunteerOpeningsService $openings,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->openings->list(),
        ]);
    }
}
