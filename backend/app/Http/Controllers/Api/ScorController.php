<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scor\ScorAccessException;
use App\Scor\ScorLocalAccounts;
use App\Scor\ScorPractice;
use App\Scor\ScorSheetService;
use App\Support\ScorGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScorController extends Controller
{
    public function __construct(private ScorSheetService $sheets) {}

    public function published(int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->publishedResults($eventId));
    }

    public function practice(int $eventId): JsonResponse
    {
        return $this->respond(fn () => app(ScorPractice::class)->sheet($eventId));
    }

    public function practiceScore(Request $request): JsonResponse
    {
        $values = $request->input('values', []);

        return $this->respond(fn () => app(ScorPractice::class)->score(is_array($values) ? $values : []));
    }

    public function login(Request $request): JsonResponse
    {
        try {
            return response()->json(app(ScorLocalAccounts::class)->login(
                (string) $request->input('username', ''),
                (string) $request->input('password', ''),
            ));
        } catch (ScorAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
    }

    public function localAccounts(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(function () use ($request, $eventId) {
            app(ScorLocalAccounts::class)->assertManager($request->user(), $eventId);

            return ['accounts' => app(ScorLocalAccounts::class)->list($eventId)];
        });
    }

    public function createLocalAccount(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => app(ScorLocalAccounts::class)->create(
            $request->user(),
            $eventId,
            (string) $request->input('username', ''),
            ScorGrant::fromFlags(
                $request->boolean('can_scor_referee'),
                $request->boolean('can_scor_jury'),
                $request->boolean('can_scor_admin'),
            ),
        ));
    }

    public function resetLocalPassword(Request $request, int $eventId, int $account): JsonResponse
    {
        return $this->respond(fn () => app(ScorLocalAccounts::class)->resetPassword($request->user(), $eventId, $account));
    }

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

    public function tagNoteworthy(Request $request, int $eventId, int $team): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->tagNoteworthy(
            $request->user(),
            $eventId,
            $team,
            $request->boolean('on'),
        ));
    }

    public function pickStage(Request $request, int $eventId): JsonResponse
    {
        $teams = $request->input('teams', []);

        return $this->respond(fn () => $this->sheets->pickStage(
            $request->user(),
            $eventId,
            is_array($teams) ? $teams : [],
        ));
    }

    public function closeEvent(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->closeEvent($request->user(), $eventId));
    }

    public function releaseEvent(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->releaseEvent($request->user(), $eventId));
    }

    public function sendResults(Request $request, int $eventId): JsonResponse
    {
        return $this->respond(fn () => $this->sheets->sendResults(
            $request->user(),
            $eventId,
            $request->exists('mail_body') ? (string) $request->input('mail_body') : null,
        ));
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
