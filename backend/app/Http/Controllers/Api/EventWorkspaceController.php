<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventWorkspaceEnsureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventWorkspaceController extends Controller
{
    public function __construct(
        private EventWorkspaceEnsureService $ensure,
    ) {}

    public function ensure(Request $request, Event $event): JsonResponse
    {
        $result = $this->ensure->ensure((int) $event->id, $request->user()?->id);

        return response()->json($result);
    }
}
