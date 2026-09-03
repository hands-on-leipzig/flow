<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Event;
use App\Services\CheckInService;
use App\Services\SeasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(
        private CheckInService $checkIn,
    ) {}

    public function getSettings(Event $event): JsonResponse
    {
        return response()->json($this->checkIn->settingsPayload($event));
    }

    public function updateSettings(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'pin' => 'sometimes|nullable|string|max:16',
            'text_teams' => 'sometimes|nullable|string',
            'text_helpers' => 'sometimes|nullable|string',
        ]);

        try {
            return response()->json($this->checkIn->updateSettings($event, $data));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function reset(Event $event): JsonResponse
    {
        $deleted = $this->checkIn->resetRecords($event);

        return response()->json(['deleted' => $deleted]);
    }

    public function bootstrap(string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        return response()
            ->json([
                'event_id' => $event->id,
                'event_name' => $event->name,
                'slug' => $event->slug,
                'enabled' => (bool) $event->check_in_enabled,
                'public_link' => $event->link,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function openSession(Request $request, string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        if (! $event->check_in_enabled) {
            return response()->json(['error' => 'Check-In ist nicht geöffnet.'], 423);
        }

        $pin = (string) $request->input('pin', '');
        if (! $this->checkIn->verifyPin($event, $pin)) {
            return response()->json(['error' => 'PIN ungültig.'], 401);
        }

        return response()->json([
            'token' => $this->checkIn->makeSessionToken($event),
            'event_id' => $event->id,
            'event_name' => $event->name,
        ]);
    }

    public function search(Request $request, string $slug): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $q = (string) $request->query('q', '');

        return response()->json([
            'results' => $this->checkIn->search($event, $q),
        ]);
    }

    public function show(Request $request, string $slug, string $subjectType, int $subjectId): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $this->assertSubjectType($subjectType);

        return response()->json($this->checkIn->detail($event, $subjectType, $subjectId));
    }

    public function checkIn(Request $request, string $slug, string $subjectType, int $subjectId): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $this->assertSubjectType($subjectType);

        $data = $request->validate([
            'reception_note' => 'nullable|string',
        ]);

        try {
            $record = $this->checkIn->checkIn(
                $event,
                $subjectType,
                $subjectId,
                $data['reception_note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'NO_SHOW_BLOCKED') {
                return response()->json([
                    'error' => 'No-Show — bitte Organisator:in kontaktieren.',
                    'code' => 'NO_SHOW_BLOCKED',
                ], 409);
            }
            throw $e;
        }

        return response()->json($this->checkIn->detail($event, $subjectType, $subjectId));
    }

    public function noShow(Request $request, string $slug, string $subjectType, int $subjectId): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $this->assertSubjectType($subjectType);

        $data = $request->validate([
            'no_show_reason' => 'required|string|min:1',
            'no_show_source' => 'required|string|min:1',
            'reception_note' => 'nullable|string',
        ]);

        $this->checkIn->markNoShow(
            $event,
            $subjectType,
            $subjectId,
            $data['no_show_reason'],
            $data['no_show_source'],
            $data['reception_note'] ?? null,
        );

        return response()->json($this->checkIn->detail($event, $subjectType, $subjectId));
    }

    public function updateNote(Request $request, string $slug, string $subjectType, int $subjectId): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $this->assertSubjectType($subjectType);

        $data = $request->validate([
            'reception_note' => 'nullable|string',
        ]);

        try {
            $this->checkIn->updateNote(
                $event,
                $subjectType,
                $subjectId,
                $data['reception_note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'NO_RECORD') {
                return response()->json([
                    'error' => 'Bitte zuerst Check-In oder No-Show speichern.',
                    'code' => 'NO_RECORD',
                ], 422);
            }
            throw $e;
        }

        return response()->json($this->checkIn->detail($event, $subjectType, $subjectId));
    }

    public function overview(Request $request, string $slug): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);

        return response()->json($this->checkIn->overview($event));
    }

    public function roster(Request $request, string $slug): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);
        $scope = (string) $request->query('scope', '');

        return response()->json($this->checkIn->roster($event, $scope));
    }

    public function organizerContact(Request $request, string $slug): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);

        return response()->json([
            'organizer' => $this->checkIn->organizerContact($event),
        ]);
    }

    public function share(Request $request, string $slug): JsonResponse
    {
        [$event] = $this->authorizedEvent($request, $slug);

        return response()->json([
            'text' => $this->checkIn->shareText($event),
        ]);
    }

    private function eventBySlug(string $slug): Event
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('season', SeasonService::currentSeasonId())
            ->first();

        if (! $event) {
            abort(404, 'Event not found');
        }

        return $event;
    }

    /**
     * @return array{0: Event}
     */
    private function authorizedEvent(Request $request, string $slug): array
    {
        $event = $this->eventBySlug($slug);

        if (! $event->check_in_enabled) {
            abort(423, 'Check-In ist nicht geöffnet.');
        }

        $token = $request->header('X-Check-In-Token') ?: $request->query('token');
        $eventId = $this->checkIn->eventIdFromSessionToken(is_string($token) ? $token : null);
        if ($eventId !== (int) $event->id) {
            abort(401, 'Check-In Sitzung ungültig.');
        }

        return [$event];
    }

    private function assertSubjectType(string $subjectType): void
    {
        if (! in_array($subjectType, [CheckIn::SUBJECT_TEAM, CheckIn::SUBJECT_VOLUNTEER], true)) {
            abort(422, 'Unknown subject type');
        }
    }
}
