<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram as ProgramEnum;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Scor\ScorAccessException;
use App\Scor\ScorPublicGame;
use App\Services\CheckInService;
use App\Services\CockpitService;
use App\Services\CockpitStagePresentationService;
use App\Services\CockpitTimeShiftService;
use App\Services\EventTitleService;
use App\Services\SeasonService;
use App\Support\ProgramCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class CockpitController extends Controller
{
    public function __construct(
        private CockpitService $cockpit,
        private CheckInService $checkIn,
        private ContaoController $contao,
        private CockpitTimeShiftService $timeShift,
        private CockpitStagePresentationService $stagePresentations,
        private EventTitleService $eventTitles,
        private ScorPublicGame $publicGame,
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

    public function reset(Event $event): JsonResponse
    {
        $deleted = $this->stagePresentations->reset($event);

        return response()->json(['deleted' => $deleted]);
    }

    public function bootstrap(string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        return response()
            ->json($this->eventTitles->withTitles([
                'event_id' => $event->id,
                'event_name' => $this->eventTitles->getEventTitleLong($event),
                'slug' => $event->slug,
                'enabled' => (bool) $event->cockpit_enabled,
                'public_link' => $event->link,
            ], $event))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function openSession(Request $request, string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        if (! $event->cockpit_enabled) {
            return response()->json(['error' => 'Cockpit ist nicht geöffnet.'], 423);
        }

        $rateKey = sprintf('day-app-pin:cockpit:%s:%s', $slug, $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json(['error' => 'Zu viele PIN-Versuche. Bitte warte eine Minute.'], 429);
        }

        $pin = (string) $request->input('pin', '');
        if (! $this->cockpit->verifyPin($event, $pin)) {
            RateLimiter::hit($rateKey, 60);

            return response()->json(['error' => 'PIN ungültig.'], 401);
        }

        RateLimiter::clear($rateKey);

        return response()->json($this->eventTitles->withTitles([
            'token' => $this->cockpit->makeSessionToken($event),
            'event_id' => $event->id,
            'event_name' => $this->eventTitles->getEventTitleLong($event),
        ], $event));
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

    /**
     * Programs with a results page in the Cockpit: Challenge (HOT rounds) and Future 8+ (SCOR rounds).
     */
    public function results(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);
        $programs = DB::table('event_program')->where('event', $event->id)->pluck('first_program')->map(fn ($id) => (int) $id)->all();

        $section = function (ProgramEnum $program): array {
            $row = FirstProgram::find($program->value);

            return [
                'program_label' => ProgramCatalog::officialNameHtml($program->value, (string) ($row->name ?? '')),
                'logo_stem' => $row->logo_stem ?? null,
            ];
        };

        return response()->json([
            'challenge' => in_array(ProgramEnum::CHALLENGE->value, $programs, true) ? $section(ProgramEnum::CHALLENGE) : null,
            'future' => in_array(ProgramEnum::FUTURE_8->value, $programs, true)
                ? $section(ProgramEnum::FUTURE_8) + $this->scorRespond(fn () => $this->publicGame->rounds($event->id))
                : null,
        ]);
    }

    public function saveGameRounds(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);
        $data = $request->validate([
            'rounds' => ['present', 'array'],
            'rounds.*' => ['integer', 'min:1'],
        ]);

        try {
            return response()->json($this->publicGame->saveRounds($event->id, $data['rounds']));
        } catch (ScorAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
    }

    public function organizerContact(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return response()->json([
            'organizer' => $this->checkIn->organizerContact($event),
        ]);
    }

    public function phonebook(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);
        $q = (string) $request->query('q', '');

        return response()->json([
            'contacts' => $this->checkIn->phonebookContacts($event, $q),
        ]);
    }

    public function overview(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return response()->json($this->checkIn->overviewAttendance($event));
    }

    public function timeshiftBootstrap(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return response()->json($this->timeShift->state($event));
    }

    public function timeshiftShift(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        $minutes = (int) $request->input('minutes');
        if (
            $minutes < CockpitTimeShiftService::MIN_MINUTES
            || $minutes > CockpitTimeShiftService::MAX_MINUTES
            || $minutes % CockpitTimeShiftService::STEP_MINUTES !== 0
        ) {
            return response()->json(['error' => 'Ungültige Anzahl Minuten.'], 422);
        }

        // "now" is always resolved server-side; a client-supplied value is ignored.
        return response()->json($this->timeShift->shift($event, $minutes));
    }

    public function stagePresentationsBootstrap(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        return response()->json($this->stagePresentations->state($event));
    }

    public function stagePresentationsSaveSelection(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        $data = $request->validate([
            'program' => ['required', 'string', 'max:50'],
            'teams' => ['present', 'array'],
            'teams.*' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $this->stagePresentations->saveSelection($event, $data['program'], $data['teams'])
        );
    }

    public function stagePresentationsSetLock(Request $request, string $slug): JsonResponse
    {
        $event = $this->authorizedEvent($request, $slug);

        $data = $request->validate([
            'program' => ['required', 'string', 'max:50'],
            'locked' => ['required', 'boolean'],
        ]);

        return response()->json(
            $this->stagePresentations->setLock($event, $data['program'], (bool) $data['locked'])
        );
    }

    /**
     * @return array{rounds: list<array<string, mixed>>}
     */
    private function scorRespond(\Closure $callback): array
    {
        try {
            return $callback();
        } catch (ScorAccessException) {
            return ['rounds' => []];
        }
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

        $token = $request->header('X-Cockpit-Token');
        $eventId = $this->cockpit->eventIdFromSessionToken(is_string($token) ? $token : null);
        if ($eventId !== (int) $event->id) {
            abort(401, 'Cockpit Sitzung ungültig.');
        }

        return $event;
    }
}
