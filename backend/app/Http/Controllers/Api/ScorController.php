<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scor\ScorAccessException;
use App\Scor\ScorSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScorController extends Controller
{
    public function __construct(private ScorSheetService $sheets) {}

    public function access(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'can_flow' => $user->isFlowUser() || $user->hasPermission(\App\Support\FlowAccess::PERMISSION_FLOW),
            'can_scor' => true,
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        return $this->respond(fn () => ['events' => $this->sheets->eventsFor($request->user())]);
    }

    public function overview(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->overview($request->user(), $eventId));
    }

    public function results(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->results($request->user(), $eventId));
    }

    public function saveAwards(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->saveAwards($request->user(), $eventId, $request->all()));
    }

    public function referee(Request $request, int $eventId, int $match, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->refereeSheet($request->user(), $eventId, $match, $team));
    }

    public function saveReferee(Request $request, int $eventId, int $match, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->saveReferee(
            $request->user(),
            $eventId,
            $match,
            $team,
            $request->all(),
        ));
    }

    public function confirmReferee(Request $request, int $eventId, int $match, int $team): JsonResponse
    {
        $side = (string) $request->input('side', '');

        return $this->respond(fn () => $this->sheets->confirmReferee($request->user(), $eventId, $match, $team, $side));
    }

    public function reopenReferee(Request $request, int $eventId, int $match, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->reopenReferee($request->user(), $eventId, $match, $team));
    }

    public function jury(Request $request, int $eventId, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->jurySheet($request->user(), $eventId, $team));
    }

    public function saveJury(Request $request, int $eventId, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->saveJury($request->user(), $eventId, $team, $request->all()));
    }

    public function completeJury(Request $request, int $eventId, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->completeJury($request->user(), $eventId, $team));
    }

    public function reopenJury(Request $request, int $eventId, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->reopenJury($request->user(), $eventId, $team));
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
