<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use App\Services\VolunteerPersonImportService;
use App\Support\GermanMobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VolunteerPersonController extends Controller
{
    /**
     * RP pool for the event's regional partner.
     */
    public function index(Request $request, Event $event): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = VolunteerPerson::query()
            ->where('regional_partner', $event->regional_partner)
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('nickname', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile', 'like', $like);
            });
        }

        $rosterIds = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->pluck('volunteer_person')
            ->all();

        $people = $query->get()->map(function (VolunteerPerson $person) use ($rosterIds, $event) {
            return $this->serializePerson($person, in_array($person->id, $rosterIds, true), $event->id);
        });

        return response()->json(['people' => $people]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'nickname' => 'nullable|string|max:100',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('volunteer_person', 'email')
                    ->where(fn ($q) => $q->where('regional_partner', $event->regional_partner)),
            ],
            'mobile' => 'nullable|string|max:50',
        ]);

        $mobile = $this->normalizeMobile($validated['mobile'] ?? null);

        $person = VolunteerPerson::create([
            'regional_partner' => $event->regional_partner,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'nickname' => $validated['nickname'] ?? null,
            'email' => strtolower(trim($validated['email'])),
            'mobile' => $mobile,
        ]);

        return response()->json([
            'person' => $this->serializePerson($person, false, $event->id),
        ], 201);
    }

    public function update(Request $request, VolunteerPerson $volunteer): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'nickname' => 'nullable|string|max:100',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('volunteer_person', 'email')
                    ->where(fn ($q) => $q->where('regional_partner', $volunteer->regional_partner))
                    ->ignore($volunteer->id),
            ],
            'mobile' => 'nullable|string|max:50',
        ]);

        if (array_key_exists('mobile', $validated)) {
            $validated['mobile'] = $this->normalizeMobile($validated['mobile']);
        }

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        $volunteer->fill($validated);
        $volunteer->save();

        return response()->json([
            'person' => $this->serializePerson($volunteer->fresh(), null, null),
        ]);
    }

    public function destroy(VolunteerPerson $volunteer): JsonResponse
    {
        if (EventVolunteerRoster::where('volunteer_person', $volunteer->id)->exists()) {
            return response()->json([
                'error' => 'Person ist noch auf mindestens einer Veranstaltungs-Anmeldung und kann nicht gelöscht werden.',
            ], 409);
        }

        if (EventStaffingAssignment::where('volunteer_person', $volunteer->id)->exists()) {
            return response()->json([
                'error' => 'Person hat noch Einsätze und kann nicht gelöscht werden.',
            ], 409);
        }

        $volunteer->delete();

        return response()->json(['ok' => true]);
    }

    public function import(Request $request, Event $event, VolunteerPersonImportService $importService): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => 'sometimes|boolean',
            'rows' => 'required|array|max:'.VolunteerPersonImportService::MAX_ROWS,
            'rows.*.first_name' => 'required|string|max:100',
            'rows.*.last_name' => 'required|string|max:100',
            'rows.*.nickname' => 'nullable|string|max:100',
            'rows.*.email' => 'required|string|max:255',
            'rows.*.mobile' => 'nullable|string|max:50',
        ]);

        $result = $importService->import(
            $event,
            $validated['rows'],
            (bool) ($validated['dry_run'] ?? false),
        );

        if ($result['errors'] !== [] && $result['results'] === []) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function exportCsv(Request $request, Event $event): StreamedResponse
    {
        $scope = $request->query('scope', 'pool'); // pool | roster
        $filename = $scope === 'roster'
            ? 'helfer-anmeldung-'.$event->id.'.csv'
            : 'helfer-pool-'.$event->regional_partner.'.csv';

        $query = VolunteerPerson::query()
            ->where('regional_partner', $event->regional_partner)
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($scope === 'roster') {
            $query->whereIn('id', EventVolunteerRoster::query()
                ->where('event', $event->id)
                ->select('volunteer_person'));
        }

        $rows = $query->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['first_name', 'last_name', 'nickname', 'email', 'mobile', 'updated_at'], ';');
            foreach ($rows as $person) {
                fputcsv($out, [
                    $person->first_name,
                    $person->last_name,
                    $person->nickname,
                    $person->email,
                    $person->mobile,
                    optional($person->updated_at)?->toIso8601String(),
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function serializePerson(VolunteerPerson $person, ?bool $onRoster, ?int $eventId): array
    {
        $payload = [
            'id' => $person->id,
            'regional_partner' => $person->regional_partner,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'nickname' => $person->nickname,
            'email' => $person->email,
            'mobile' => $person->mobile,
            'created_at' => optional($person->created_at)?->toIso8601String(),
            'updated_at' => optional($person->updated_at)?->toIso8601String(),
        ];

        if ($onRoster !== null) {
            $payload['on_roster'] = $onRoster;
        }

        if ($eventId) {
            $payload['recent_assignments'] = $this->recentAssignments($person->id, $eventId);
        }

        return $payload;
    }

    private function normalizeMobile(?string $mobile): ?string
    {
        $result = GermanMobileNumber::validateAndNormalize($mobile);
        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'mobile' => [$result['error']],
            ]);
        }

        return $result['normalized'];
    }

    /**
     * Compact history for staffing context (other events).
     *
     * @return list<array{event_id:int,role:string,year:?string}>
     */
    private function recentAssignments(int $personId, int $excludeEventId): array
    {
        return DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->join('event as e', 'e.id', '=', 'r.event')
            ->leftJoin('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->leftJoin('m_season as s', 's.id', '=', 'e.season')
            ->where('a.volunteer_person', $personId)
            ->where('r.event', '!=', $excludeEventId)
            ->orderByDesc('e.date')
            ->limit(5)
            ->get([
                'e.id as event_id',
                'e.date as event_date',
                's.name as season_name',
                's.year as season_year',
                'mr.name as catalog_role',
                'r.label as local_label',
            ])
            ->map(fn ($row) => [
                'event_id' => (int) $row->event_id,
                'role' => $row->catalog_role ?: ($row->local_label ?: 'Rolle'),
                'year' => $row->season_year ?: ($row->season_name ?: (string) $row->event_date),
            ])
            ->all();
    }
}
