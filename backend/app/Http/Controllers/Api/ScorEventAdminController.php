<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scor\ScorAccessException;
use App\Scor\ScorEventAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScorEventAdminController extends Controller
{
    public function __construct(private ScorEventAdmin $admin) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->admin->list($request->user(), (string) $request->query('q', '')));
    }

    public function release(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->admin->release($request->user(), $eventId));
    }

    public function withdraw(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->admin->withdrawRelease($request->user(), $eventId));
    }

    public function resetTable(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->admin->resetTable($request->user(), $eventId));
    }

    public function resetScores(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->admin->resetEvaluations($request->user(), $eventId));
    }

    private function respond(\Closure $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ScorAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
    }
}
