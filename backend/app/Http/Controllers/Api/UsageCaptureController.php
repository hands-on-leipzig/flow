<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicAccessRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageCaptureController extends Controller
{
    public function __construct(
        private PublicAccessRecorder $recorder,
    ) {}

    public function surface(Request $request): JsonResponse
    {
        $eventId = (int) $request->input('event_id');
        if ($eventId < 1) {
            return response()->json(['error' => 'event_id is required'], 400);
        }

        $kind = (string) $request->input('kind', '');
        $result = $this->recorder->recordSurface($request, $eventId, $kind);

        return $this->jsonResult($result);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $eventId = (int) $request->input('event_id');
        if ($eventId < 1) {
            return response()->json(['error' => 'event_id is required'], 400);
        }

        $deviceId = (string) $request->input('device_id', '');
        $result = $this->recorder->heartbeatDisplay($request, $eventId, $deviceId);

        return $this->jsonResult($result);
    }

    /**
     * @param  array{success: bool, skipped?: bool, error?: string, status?: int}  $result
     */
    private function jsonResult(array $result): JsonResponse
    {
        if ($result['success']) {
            return response()->json(['success' => true]);
        }

        return response()->json(
            ['error' => $result['error'] ?? 'Failed to log access'],
            $result['status'] ?? 500
        );
    }
}
