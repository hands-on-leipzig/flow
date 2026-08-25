<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\FlowAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserAccessController extends Controller
{
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
                $selectedEvent = [
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->date,
                    'season' => $event->seasonRel?->name,
                    'regional_partner' => $event->regionalPartner?->name,
                ];
            }
        }

        $partners = $user->regionalPartners()
            ->withPivot(['source', 'granted_at', 'granted_by'])
            ->orderBy('regional_partner.name')
            ->get()
            ->map(fn ($rp) => [
                'id' => $rp->id,
                'name' => $rp->name,
                'region' => $rp->region,
                'source' => $rp->pivot->source ?? FlowAccess::SOURCE_DRAHT,
                'granted_at' => $rp->pivot->granted_at,
            ]);

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

        $myPartners = $user->regionalPartners()
            ->withPivot(['source', 'granted_at', 'granted_by'])
            ->orderBy('regional_partner.name')
            ->get();

        $partnerIds = $myPartners->pluck('id')->all();
        $eventsBySeason = [];
        $flatEvents = [];

        if ($partnerIds !== []) {
            $events = Event::query()
                ->whereIn('regional_partner', $partnerIds)
                ->with(['seasonRel', 'levelRel', 'regionalPartner'])
                ->orderBy('date')
                ->get();

            $flatEvents = $events->map(fn (Event $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'date' => $e->date,
                'level' => $e->levelRel?->name,
                'season_id' => $e->seasonRel?->id,
                'season_name' => $e->seasonRel?->name,
                'season_year' => $e->seasonRel?->year,
                'regional_partner_id' => (int) $e->regional_partner,
                'regional_partner_name' => $e->regionalPartner?->name,
            ])->values()->all();

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
                            ->map(fn (Event $e) => [
                                'id' => $e->id,
                                'name' => $e->name,
                                'date' => $e->date,
                                'level' => $e->levelRel?->name,
                                'regional_partner_id' => (int) $e->regional_partner,
                                'regional_partner_name' => $e->regionalPartner?->name,
                            ]),
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
            'my_partners' => $myPartners->map(fn ($rp) => [
                'id' => $rp->id,
                'name' => $rp->name,
                'region' => $rp->region,
                'source' => $rp->pivot->source ?? FlowAccess::SOURCE_DRAHT,
                'granted_at' => $rp->pivot->granted_at,
            ])->values(),
            'events_by_season' => $eventsBySeason,
            'events' => $flatEvents,
            'selected_event_id' => $selectedEventId ?: null,
        ]);
    }

    /**
     * Accounts that can access a specific event (via its regional partner).
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
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'date' => $event->date,
                'regional_partner_id' => (int) $event->regional_partner,
                'regional_partner_name' => $event->regionalPartner?->name,
            ],
            'users' => $this->usersForPartner((int) $event->regional_partner),
        ]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['users' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $users = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('nick', 'like', $like);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'nick'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'nick' => $u->nick,
                'display_name' => trim(($u->name ?: 'Unbekannt') . ($u->email ? " ({$u->email})" : '')),
            ]);

        return response()->json(['users' => $users]);
    }

    public function grant(Request $request): JsonResponse
    {
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:user,id',
            'event_id' => 'required|integer|exists:event,id',
        ]);

        $event = Event::find($validated['event_id']);
        if (!$event) {
            return response()->json(['error' => 'Veranstaltung nicht gefunden'], 404);
        }

        $regionalPartnerId = (int) $event->regional_partner;

        if (!$actor->hasEventAccess((int) $event->id)) {
            return response()->json(['error' => 'Forbidden — kein Zugriff auf diese Veranstaltung'], 403);
        }

        if ((int) $validated['user_id'] === (int) $actor->id) {
            return response()->json(['error' => 'Du hast bereits Zugriff.'], 422);
        }

        $existing = DB::table('user_regional_partner')
            ->where('user', $validated['user_id'])
            ->where('regional_partner', $regionalPartnerId)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'Zugriff besteht bereits',
                'source' => $existing->source ?? FlowAccess::SOURCE_DRAHT,
            ], 409);
        }

        // Access in FLOW is modelled per regional partner (covers that partner's events).
        DB::table('user_regional_partner')->insert([
            'user' => $validated['user_id'],
            'regional_partner' => $regionalPartnerId,
            'source' => FlowAccess::SOURCE_MANUAL,
            'granted_at' => now(),
            'granted_by' => $actor->id,
        ]);

        Log::info('User access grant created via event', [
            'user_id' => $validated['user_id'],
            'event_id' => $event->id,
            'regional_partner_id' => $regionalPartnerId,
            'granted_by' => $actor->id,
        ]);

        return response()->json([
            'message' => 'Zugriff gewährt',
            'source' => FlowAccess::SOURCE_MANUAL,
            'users' => $this->usersForPartner($regionalPartnerId),
        ], 201);
    }

    public function revoke(Request $request): JsonResponse
    {
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:user,id',
            'event_id' => 'required|integer|exists:event,id',
        ]);

        $event = Event::find($validated['event_id']);
        if (!$event) {
            return response()->json(['error' => 'Veranstaltung nicht gefunden'], 404);
        }

        $regionalPartnerId = (int) $event->regional_partner;

        if (!$actor->hasEventAccess((int) $event->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $relation = DB::table('user_regional_partner')
            ->where('user', $validated['user_id'])
            ->where('regional_partner', $regionalPartnerId)
            ->first();

        if (!$relation) {
            return response()->json(['error' => 'Zuordnung nicht gefunden'], 404);
        }

        if (($relation->source ?? FlowAccess::SOURCE_DRAHT) === FlowAccess::SOURCE_DRAHT) {
            return response()->json([
                'error' => 'Draht-Zugänge können hier nicht entfernt werden. Bitte in Draht die Kontaktperson ändern.',
                'source' => FlowAccess::SOURCE_DRAHT,
            ], 409);
        }

        DB::table('user_regional_partner')
            ->where('user', $validated['user_id'])
            ->where('regional_partner', $regionalPartnerId)
            ->where('source', FlowAccess::SOURCE_MANUAL)
            ->delete();

        Log::info('User access grant revoked via event', [
            'user_id' => $validated['user_id'],
            'event_id' => $event->id,
            'regional_partner_id' => $regionalPartnerId,
            'revoked_by' => $actor->id,
        ]);

        return response()->json([
            'message' => 'Zugriff entfernt',
            'users' => $this->usersForPartner($regionalPartnerId),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usersForPartner(int $regionalPartnerId): array
    {
        return DB::table('user_regional_partner as urp')
            ->join('user as u', 'urp.user', '=', 'u.id')
            ->leftJoin('user as granter', 'urp.granted_by', '=', 'granter.id')
            ->where('urp.regional_partner', $regionalPartnerId)
            ->orderBy('u.name')
            ->get([
                'u.id as user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.nick as user_nick',
                'u.last_login',
                'urp.source',
                'urp.granted_at',
                'urp.granted_by',
                'granter.name as granted_by_name',
            ])
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
                'nick' => $row->user_nick,
                'last_login' => $row->last_login,
                'source' => $row->source ?? FlowAccess::SOURCE_DRAHT,
                'granted_at' => $row->granted_at,
                'granted_by' => $row->granted_by,
                'granted_by_name' => $row->granted_by_name,
                'is_self' => (int) $row->user_id === (int) Auth::id(),
            ])
            ->values()
            ->all();
    }
}
