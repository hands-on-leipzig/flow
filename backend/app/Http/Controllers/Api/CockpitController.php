<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\CockpitService;
use App\Services\SeasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CockpitController extends Controller
{
    public function __construct(
        private CockpitService $cockpit,
        private ContaoController $contao,
    ) {}

    public function getSettings(Event $event): JsonResponse
    {
        return response()->json($this->cockpit->settingsPayload($event));
    }

    public function updateSettings(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'pin' => 'sometimes|nullable|string|max:16',
        ]);

        try {
            return response()->json($this->cockpit->updateSettings($event, $data));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bootstrap(string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        return response()
            ->json([
                'event_id' => $event->id,
                'event_name' => $event->name,
                'slug' => $event->slug,
                'enabled' => (bool) $event->cockpit_enabled,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function openSession(Request $request, string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        if (! $event->cockpit_enabled) {
            return response()->json(['error' => 'Cockpit ist nicht geöffnet.'], 423);
        }

        $pin = (string) $request->input('pin', '');
        if (! $this->cockpit->verifyPin($event, $pin)) {
            return response()->json(['error' => 'PIN ungültig.'], 401);
        }

        return response()->json([
            'token' => $this->cockpit->makeSessionToken($event),
            'event_id' => $event->id,
            'event_name' => $event->name,
        ]);
    }

    public function getRounds(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return $this->contao->getRoundsToShowEndpoint($request, $event->id);
    }

    public function saveRounds(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return $this->contao->saveRoundsToShow($request, $event->id);
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

    private function authorizedEvent(Request $request, string $slug): Event
    {
        $event = $this->eventBySlug($slug);

        if (! $event->cockpit_enabled) {
            abort(423, 'Cockpit ist nicht geöffnet.');
        }

        $token = $request->header('X-Cockpit-Token') ?: $request->query('token');
        $eventId = $this->cockpit->eventIdFromSessionToken(is_string($token) ? $token : null);
        if ($eventId !== (int) $event->id) {
            abort(401, 'Cockpit Sitzung ungültig.');
        }

        return $event;
    }
}
