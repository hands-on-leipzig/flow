<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\EventAccessService;
use App\Services\EventTitleService;
use App\Support\FlowAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserAccessController extends Controller
{
    public function __construct(
        private EventTitleService $eventTitles,
        private EventAccessService $access,
    ) {}

    /**
     * Current user profile (DB + roles from JWT).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $roles = FlowAccess::rolesFromJwt($request->attributes->get('jwt'));
        $selectedEvent = null;
        if ($user->selection_event) {
            $event = Event::with(['seasonRel', 'levelRel', 'regionalPartner'])->find($user->selection_event);
            if ($event) {
                $selectedEvent = $this->eventTitles->withTitles([
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->date,
                    'season' => $event->seasonRel?->name,
                    'regional_partner' => $event->regionalPartner?->name,
                ], $event);
            }
        }

        $partners = $this->partnersOf($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'nick' => $user->nick,
                'dolibarr_id' => $user->dolibarr_id,
                'lang' => $user->lang,
                'last_login' => $user->last_login,
                'roles' => $roles,
                'is_admin' => FlowAccess::isAdmin($roles),
            ],
            'selected_event' => $selectedEvent,
            'regional_partners' => $partners,
        ]);
    }

    /**
     * Access overview for normal users: my partners + my events.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $myPartners = $this->partnersOf($user);

        $eventIds = $user->accessibleEventIds(FlowAccess::PERMISSION_FLOW);
        $eventsBySeason = [];
        $flatEvents = [];

        if ($eventIds !== []) {
            $events = Event::query()
                ->whereIn('id', $eventIds)
                ->with(['seasonRel', 'levelRel', 'regionalPartner'])
                ->orderBy('date')
                ->get();

            $flatEvents = $events->map(fn (Event $e) => $this->eventTitles->withTitles([
                'id' => $e->id,
                'name' => $e->name,
                'date' => $e->date,
                'level' => $e->levelRel?->name,
                'season_id' => $e->seasonRel?->id,
                'season_name' => $e->seasonRel?->name,
                'season_year' => $e->seasonRel?->year,
                'regional_partner_id' => (int) $e->regional_partner,
                'regional_partner_name' => $e->regionalPartner?->name,
            ], $e))->values()->all();

            $eventsBySeason = $events
                ->groupBy(fn (Event $e) => $e->seasonRel?->id ?? 0)
                ->map(function ($seasonEvents) {
                    /** @var Event $first */
                    $first = $seasonEvents->first();
                    return [
                        'season' => [
                            'id' => $first->seasonRel?->id,
                            'name' => $first->seasonRel?->name,
                            'year' => $first->seasonRel?->year,
                        ],
                        'events' => $seasonEvents
                            ->sortBy('date')
                            ->values()
                            ->map(fn (Event $e) => $this->eventTitles->withTitles([
                                'id' => $e->id,
                                'name' => $e->name,
                                'date' => $e->date,
                                'level' => $e->levelRel?->name,
                                'regional_partner_id' => (int) $e->regional_partner,
                                'regional_partner_name' => $e->regionalPartner?->name,
                            ], $e)),
                    ];
                })
                ->sortByDesc(fn ($g) => $g['season']['year'] ?? 0)
                ->values()
                ->all();
        }

        $selectedEventId = (int) ($request->query('event') ?: $user->selection_event ?: 0);
        if ($selectedEventId && !$user->hasEventAccess($selectedEventId)) {
            $selectedEventId = 0;
        }

        return response()->json([
            'my_partners' => $myPartners,
            'events_by_season' => $eventsBySeason,
            'events' => $flatEvents,
            'selected_event_id' => $selectedEventId ?: null,
        ]);
    }

    /**
     * Accounts and pending invitations for a specific event's regional partner.
     */
    public function eventUsers(Request $request, int $eventId): JsonResponse
    {
        $user = $request->user() ?? Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$user->hasEventAccess($eventId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $event = Event::with('regionalPartner')->find($eventId);
        if (!$event) {
            return response()->json(['error' => 'Veranstaltung nicht gefunden'], 404);
        }

        return response()->json([
            'event' => $this->eventTitles->withTitles([
                'id' => $event->id,
                'name' => $event->name,
                'date' => $event->date,
                'regional_partner_id' => (int) $event->regional_partner,
                'regional_partner_name' => $event->regionalPartner?->name,
            ], $event),
            ...$this->accessPayload($user, (int) $event->id, (int) $event->regional_partner),
        ]);
    }

    /**
     * Invite an SSO account by email to this event only.
     */
    public function grant(Request $request): JsonResponse
    {
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'event_id' => 'required|integer|exists:event,id',
            'email' => 'required|email|max:255',
            'can_flow' => 'required|boolean',
            'can_scor' => 'required|boolean',
        ]);

        $eventId = (int) $validated['event_id'];
        $regionalPartnerId = $this->managedRegionalPartner($actor, $eventId);
        if ($regionalPartnerId instanceof JsonResponse) {
            return $regionalPartnerId;
        }

        if (!$validated['can_flow'] && !$validated['can_scor']) {
            return response()->json(['error' => 'Bitte wähle mindestens eine Berechtigung.'], 422);
        }

        $email = EventAccessService::normalizeEmail($validated['email']);

        if ($this->access->emailHasAccess($eventId, $email)) {
            return response()->json(['error' => 'Diese E-Mail-Adresse hat bereits Zugriff auf diese Veranstaltung.'], 409);
        }

        $pending = DB::table('event_invitation')
            ->where('event', $eventId)
            ->where('email', $email)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->exists();
        if ($pending) {
            return response()->json(['error' => 'Für diese E-Mail-Adresse gibt es schon eine Einladung zu dieser Veranstaltung.'], 409);
        }

        $created = $this->access->invite(
            $actor,
            $eventId,
            $regionalPartnerId,
            $email,
            (bool) $validated['can_flow'],
            (bool) $validated['can_scor'],
        );

        return response()->json([
            'message' => 'Einladung gespeichert',
            'mail_sent' => $created['mail_sent'],
            ...$this->accessPayload($actor, $eventId, $regionalPartnerId),
        ], 201);
    }

    /**
     * Change FLOW / SCOR permissions of a manual grant or a pending invitation.
     */
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'event_id' => 'required|integer|exists:event,id',
            'user_id' => 'nullable|required_without:invitation_id|integer',
            'invitation_id' => 'nullable|required_without:user_id|integer',
            'can_flow' => 'required|boolean',
            'can_scor' => 'required|boolean',
        ]);

        $eventId = (int) $validated['event_id'];
        $regionalPartnerId = $this->managedRegionalPartner($actor, $eventId);
        if ($regionalPartnerId instanceof JsonResponse) {
            return $regionalPartnerId;
        }

        if (!$validated['can_flow'] && !$validated['can_scor']) {
            return response()->json(['error' => 'Mindestens eine Berechtigung muss bleiben. Zum Entfernen bitte „Entfernen“ nutzen.'], 422);
        }

        $canFlow = (bool) $validated['can_flow'];
        $canScor = (bool) $validated['can_scor'];

        if (!empty($validated['invitation_id'])) {
            $invitation = DB::table('event_invitation')
                ->where('id', $validated['invitation_id'])
                ->where('event', $eventId)
                ->first();
            if (!$invitation) {
                return response()->json(['error' => 'Einladung nicht gefunden'], 404);
            }

            $this->access->updateInvitation((int) $invitation->id, $eventId, $regionalPartnerId, $actor, $canFlow, $canScor);
        } else {
            $grant = $this->eventGrant($actor, (int) $validated['user_id'], $eventId);
            if ($grant instanceof JsonResponse) {
                return $grant;
            }

            $this->access->updateGrant((int) $grant->id, $eventId, $regionalPartnerId, $actor, (int) $grant->user, $canFlow, $canScor);
        }

        return response()->json([
            'message' => 'Berechtigungen gespeichert',
            ...$this->accessPayload($actor, $eventId, $regionalPartnerId),
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'event_id' => 'required|integer|exists:event,id',
            'user_id' => 'nullable|required_without:invitation_id|integer',
            'invitation_id' => 'nullable|required_without:user_id|integer',
        ]);

        $eventId = (int) $validated['event_id'];
        $regionalPartnerId = $this->managedRegionalPartner($actor, $eventId);
        if ($regionalPartnerId instanceof JsonResponse) {
            return $regionalPartnerId;
        }

        if (!empty($validated['invitation_id'])) {
            $invitation = DB::table('event_invitation')
                ->where('id', $validated['invitation_id'])
                ->where('event', $eventId)
                ->first();
            if (!$invitation) {
                return response()->json(['error' => 'Einladung nicht gefunden'], 404);
            }

            $this->access->withdrawInvitation((int) $invitation->id, $eventId, $regionalPartnerId, $actor);
        } else {
            $grant = $this->eventGrant($actor, (int) $validated['user_id'], $eventId);
            if ($grant instanceof JsonResponse) {
                return $grant;
            }

            $this->access->revokeGrant(
                (int) $grant->id,
                $eventId,
                $regionalPartnerId,
                $actor,
                (int) $grant->user,
                (bool) $grant->can_flow,
                (bool) $grant->can_scor,
            );
        }

        return response()->json([
            'message' => 'Zugriff entfernt',
            ...$this->accessPayload($actor, $eventId, $regionalPartnerId),
        ]);
    }

    /**
     * Regional partner of the event, if the actor may manage its access.
     */
    private function managedRegionalPartner(User $actor, int $eventId): int|JsonResponse
    {
        $regionalPartnerId = (int) DB::table('event')->where('id', $eventId)->value('regional_partner');
        if ($regionalPartnerId <= 0) {
            return response()->json(['error' => 'Veranstaltung nicht gefunden'], 404);
        }

        if (!$actor->canManageRegionalPartner($regionalPartnerId)) {
            return response()->json(['error' => 'Forbidden — nur Kontaktpersonen aus Draht können Zugänge verwalten.'], 403);
        }

        return $regionalPartnerId;
    }

    private function eventGrant(User $actor, int $userId, int $eventId): \stdClass|JsonResponse
    {
        if ($userId === (int) $actor->id) {
            return response()->json(['error' => 'Den eigenen Zugang kannst du hier nicht ändern.'], 422);
        }

        $grant = DB::table('event_user_access')
            ->where('user', $userId)
            ->where('event', $eventId)
            ->first();

        if (!$grant) {
            $draht = DB::table('user_regional_partner as urp')
                ->join('event', 'event.regional_partner', '=', 'urp.regional_partner')
                ->where('event.id', $eventId)
                ->where('urp.user', $userId)
                ->where('urp.source', FlowAccess::SOURCE_DRAHT)
                ->exists();

            if ($draht) {
                return response()->json([
                    'error' => 'Draht-Zugänge können hier nicht geändert werden. Bitte in Draht die Kontaktperson ändern.',
                    'source' => FlowAccess::SOURCE_DRAHT,
                ], 409);
            }

            return response()->json(['error' => 'Zuordnung nicht gefunden'], 404);
        }

        return $grant;
    }

    /**
     * @return array{users: list<array<string, mixed>>, invitations: list<array<string, mixed>>, log: list<array<string, mixed>>, can_manage: bool}
     */
    private function accessPayload(User $viewer, int $eventId, int $regionalPartnerId): array
    {
        $canManage = $viewer->canManageRegionalPartner($regionalPartnerId);

        return [
            'users' => $this->usersForEvent($eventId, $regionalPartnerId),
            'invitations' => $canManage ? $this->access->pendingForEvent($eventId) : [],
            'log' => $canManage ? $this->access->logForEvent($eventId) : [],
            'can_manage' => $canManage,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partnersOf(User $user): array
    {
        return $user->regionalPartners()
            ->withPivot(['source', 'can_flow', 'can_scor', 'granted_at'])
            ->orderBy('regional_partner.name')
            ->get()
            ->map(fn ($rp) => [
                'id' => $rp->id,
                'name' => $rp->name,
                'region' => $rp->region,
                'source' => $rp->pivot->source ?? FlowAccess::SOURCE_DRAHT,
                'can_flow' => (bool) $rp->pivot->can_flow,
                'can_scor' => (bool) $rp->pivot->can_scor,
                'granted_at' => $rp->pivot->granted_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Draht contacts of the regional partner, plus people invited to this event.
     *
     * @return list<array<string, mixed>>
     */
    private function usersForEvent(int $eventId, int $regionalPartnerId): array
    {
        $draht = DB::table('user_regional_partner as urp')
            ->join('user as u', 'urp.user', '=', 'u.id')
            ->where('urp.regional_partner', $regionalPartnerId)
            ->where('urp.source', FlowAccess::SOURCE_DRAHT)
            ->orderBy('u.name')
            ->get([
                'u.id as user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.last_login',
                'urp.can_flow',
                'urp.can_scor',
                'urp.granted_at',
            ])
            ->map(fn ($row) => $this->userRow($row, FlowAccess::SOURCE_DRAHT, null))
            ->all();

        $drahtIds = array_map(fn ($row) => (int) $row['user_id'], $draht);

        $invited = DB::table('event_user_access as access')
            ->join('user as u', 'access.user', '=', 'u.id')
            ->leftJoin('user as granter', 'access.granted_by', '=', 'granter.id')
            ->where('access.event', $eventId)
            ->when($drahtIds !== [], fn ($query) => $query->whereNotIn('access.user', $drahtIds))
            ->orderBy('u.name')
            ->get([
                'u.id as user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.last_login',
                'access.can_flow',
                'access.can_scor',
                'access.granted_at',
                'granter.name as granted_by_name',
            ])
            ->map(fn ($row) => $this->userRow($row, FlowAccess::SOURCE_MANUAL, $row->granted_by_name))
            ->all();

        return [...$draht, ...$invited];
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(object $row, string $source, ?string $grantedByName): array
    {
        return [
            'user_id' => $row->user_id,
            'name' => $row->user_name,
            'email' => $row->user_email,
            'last_login' => $row->last_login,
            'source' => $source,
            'can_flow' => (bool) $row->can_flow,
            'can_scor' => (bool) $row->can_scor,
            'granted_at' => $row->granted_at,
            'granted_by_name' => $grantedByName,
            'is_self' => (int) $row->user_id === (int) Auth::id(),
        ];
    }
}
