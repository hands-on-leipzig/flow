<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventVolunteerRoster;
use App\Services\EventAccessService;
use App\Support\ScorGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScorAdminController extends Controller
{
    public function __construct(
        private EventAccessService $access,
    ) {}

    public function events(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $events = DB::table('event as e')
            ->leftJoin('regional_partner as rp', 'e.regional_partner', '=', 'rp.id')
            ->when(mb_strlen($q) >= 2, function ($query) use ($like) {
                $query->where(function ($inner) use ($like) {
                    $inner->where('e.name', 'like', $like)
                        ->orWhere('rp.name', 'like', $like);
                });
            })
            ->orderByDesc('e.date')
            ->limit(40)
            ->get([
                'e.id',
                'e.name',
                'e.date',
                'rp.name as regional_partner_name',
            ]);

        return response()->json(['events' => $events]);
    }

    public function helpers(int $eventId): JsonResponse
    {
        if (!Schema::hasTable('event_volunteer_roster') || !Schema::hasTable('volunteer_person')) {
            return response()->json(['helpers' => []]);
        }

        $people = EventVolunteerRoster::query()
            ->where('event', $eventId)
            ->with('person')
            ->get()
            ->map(function ($roster) {
                $person = $roster->person;
                if (!$person) {
                    return null;
                }
                $email = EventAccessService::normalizeEmail((string) $person->email);
                $user = $email !== ''
                    ? DB::table('user')->whereRaw('lower(email) = ?', [$email])->first()
                    : null;

                return [
                    'id' => (int) $person->id,
                    'name' => trim($person->first_name.' '.$person->last_name),
                    'email' => $person->email,
                    'has_account' => $user !== null,
                ];
            })
            ->filter()
            ->sortBy(fn ($row) => mb_strtolower($row['name']))
            ->values();

        return response()->json(['helpers' => $people]);
    }

    public function grantHelper(Request $request, int $eventId): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $personId = (int) $request->input('volunteer_person');
        $roles = ScorGrant::fromFlags(
            (bool) $request->boolean('can_scor_referee'),
            (bool) $request->boolean('can_scor_jury'),
            (bool) $request->boolean('can_scor_admin'),
        );
        if (!$roles->any()) {
            return response()->json(['error' => 'Mindestens eine SCOR-Berechtigung wählen.'], 422);
        }
        $onRoster = EventVolunteerRoster::query()
            ->where('event', $eventId)
            ->where('volunteer_person', $personId)
            ->first();
        $person = $onRoster?->person;
        if (!$person) {
            return response()->json(['error' => 'Die Person steht nicht auf der Helferliste.'], 422);
        }
        $email = EventAccessService::normalizeEmail((string) $person->email);
        $userId = $email !== ''
            ? DB::table('user')->whereRaw('lower(email) = ?', [$email])->value('id')
            : null;
        if (!$userId) {
            return response()->json([
                'error' => 'Kein HERO-Konto zu dieser E-Mail. Eine Passwort-Anmeldung ohne HERO ist noch nicht angeschlossen.',
            ], 422);
        }

        $this->access->grantUser($actor, $eventId, (int) $userId, $roles, false);

        return response()->json(['ok' => true]);
    }

    public function log(): JsonResponse
    {
        return response()->json(['log' => $this->access->logRecent()]);
    }
}
