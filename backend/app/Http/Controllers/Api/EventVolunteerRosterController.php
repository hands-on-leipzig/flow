<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventVolunteerRoster;
use App\Models\EventVolunteerRosterDetail;
use App\Models\VolunteerPerson;
use App\Support\VolunteerRosterDetailFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventVolunteerRosterController extends Controller
{
    private const EXPORT_ASSIGNMENT_PAIRS = 5;

    public function index(Event $event): JsonResponse
    {
        $rows = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->with(['person', 'detail'])
            ->get()
            ->sortBy(fn (EventVolunteerRoster $row) => [
                mb_strtolower($row->person?->last_name ?? ''),
                mb_strtolower($row->person?->first_name ?? ''),
            ])
            ->values();

        $assignedPersonIds = $this->assignedPersonIds($event->id);
        $assignmentsByPerson = $this->assignmentsByPerson($event->id);

        $roster = $rows->map(function (EventVolunteerRoster $row) use ($assignedPersonIds, $assignmentsByPerson) {
            $person = $row->person;
            if (! $person) {
                return null;
            }

            return [
                'id' => $row->id,
                'event' => $row->event,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'has_assignment' => in_array($person->id, $assignedPersonIds, true),
                'assignments' => $assignmentsByPerson[$person->id] ?? [],
                'detail' => VolunteerRosterDetailFields::serialize($row->detail),
                'person' => [
                    'id' => $person->id,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'nickname' => $person->nickname,
                    'email' => $person->email,
                    'mobile' => $person->mobile,
                    'updated_at' => optional($person->updated_at)?->toIso8601String(),
                ],
            ];
        })->filter()->values();

        return response()->json(['roster' => $roster]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'volunteer_person' => 'required|integer|exists:volunteer_person,id',
        ]);

        $person = VolunteerPerson::findOrFail($validated['volunteer_person']);
        if ((int) $person->regional_partner !== (int) $event->regional_partner) {
            return response()->json([
                'error' => 'Person gehört nicht zum Regionalpartner dieser Veranstaltung.',
            ], 422);
        }

        $row = EventVolunteerRoster::firstOrCreate(
            [
                'event' => $event->id,
                'volunteer_person' => $person->id,
            ],
            [
                'created_at' => now(),
            ]
        );

        return response()->json([
            'roster_entry' => [
                'id' => $row->id,
                'event' => $row->event,
                'volunteer_person' => $row->volunteer_person,
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ],
        ], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function updateDetail(Request $request, Event $event, VolunteerPerson $volunteer): JsonResponse
    {
        if ((int) $volunteer->regional_partner !== (int) $event->regional_partner) {
            return response()->json(['error' => 'Person gehört nicht zu diesem Regionalpartner.'], 422);
        }

        $roster = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $volunteer->id)
            ->first();

        if (! $roster) {
            return response()->json(['error' => 'Person ist nicht auf der Helferliste.'], 404);
        }

        $existing = $roster->detail;
        $payload = [
            't_shirt_cut' => $request->input('t_shirt_cut', $existing?->t_shirt_cut),
            't_shirt_size' => $request->input('t_shirt_size', $existing?->t_shirt_size),
            'meal' => $request->input('meal', $existing?->meal),
            'eve_meeting' => $request->has('eve_meeting')
                ? $request->input('eve_meeting')
                : $existing?->eve_meeting,
            'notes' => $request->has('notes') ? $request->input('notes') : $existing?->notes,
        ];

        $validation = VolunteerRosterDetailFields::validate($payload);
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        $detail = EventVolunteerRosterDetail::query()->updateOrCreate(
            ['event_volunteer_roster' => $roster->id],
            array_merge($validation['data'], ['updated_at' => now()])
        );

        return response()->json([
            'detail' => VolunteerRosterDetailFields::serialize($detail),
        ]);
    }

    public function exportCsv(Event $event): StreamedResponse
    {
        $rows = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->with(['person', 'detail'])
            ->get()
            ->sortBy(fn (EventVolunteerRoster $row) => [
                mb_strtolower($row->person?->last_name ?? ''),
                mb_strtolower($row->person?->first_name ?? ''),
            ])
            ->values();

        $assignmentsByPerson = $this->assignmentsByPerson($event->id);
        $programNames = $this->programNameMap();

        $header = [
            'first_name',
            'last_name',
            'nickname',
            'email',
            'mobile',
            't_shirt_cut',
            't_shirt_size',
            'meal',
            'eve_meeting',
            'notes',
        ];
        for ($i = 1; $i <= self::EXPORT_ASSIGNMENT_PAIRS; $i++) {
            $header[] = "zuordnung_{$i}_program";
            $header[] = "zuordnung_{$i}_role";
        }

        return response()->streamDownload(function () use ($rows, $assignmentsByPerson, $programNames, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');

            foreach ($rows as $row) {
                $person = $row->person;
                if (! $person) {
                    continue;
                }

                $detail = $row->detail;
                $line = [
                    $person->first_name,
                    $person->last_name,
                    $person->nickname,
                    $person->email,
                    $person->mobile,
                    VolunteerRosterDetailFields::exportLabel($detail?->t_shirt_cut),
                    $detail?->t_shirt_size ?? '',
                    VolunteerRosterDetailFields::exportMealLabel($detail?->meal),
                    VolunteerRosterDetailFields::exportEveMeeting($detail?->eve_meeting),
                    $detail?->notes ?? '',
                ];

                $assignments = $assignmentsByPerson[$person->id] ?? [];
                for ($i = 0; $i < self::EXPORT_ASSIGNMENT_PAIRS; $i++) {
                    $assignment = $assignments[$i] ?? null;
                    if ($assignment) {
                        $line[] = $this->assignmentProgramLabel($assignment, $programNames);
                        $line[] = $assignment['label'];
                    } else {
                        $line[] = '';
                        $line[] = '';
                    }
                }

                fputcsv($out, $line, ';');
            }

            fclose($out);
        }, 'helferliste-'.$event->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroy(Event $event, VolunteerPerson $volunteer): JsonResponse
    {
        if ((int) $volunteer->regional_partner !== (int) $event->regional_partner) {
            return response()->json(['error' => 'Person gehört nicht zu diesem Regionalpartner.'], 422);
        }

        DB::transaction(function () use ($event, $volunteer) {
            $this->deleteAssignmentsOnEvent($event->id, $volunteer->id);

            EventVolunteerRoster::query()
                ->where('event', $event->id)
                ->where('volunteer_person', $volunteer->id)
                ->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, string>
     */
    private function programNameMap(): array
    {
        return DB::table('m_first_program')
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param  array{first_program: ?int, is_local: bool}  $assignment
     * @param  array<int, string>  $programNames
     */
    private function assignmentProgramLabel(array $assignment, array $programNames): string
    {
        if ($assignment['is_local']) {
            return 'Zusätzlich';
        }
        if ($assignment['first_program'] === null) {
            return 'Übergreifend';
        }

        return $programNames[$assignment['first_program']] ?? (string) $assignment['first_program'];
    }

    /**
     * @return list<int>
     */
    private function assignedPersonIds(int $eventId): array
    {
        return DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $eventId)
            ->pluck('a.volunteer_person')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, list<array{tile_name: string, label: string, role_id: int, first_program: ?int, is_local: bool, sequence: int, group_index: int}>>
     */
    private function assignmentsByPerson(int $eventId): array
    {
        $groupCounts = DB::table('event_staffing_group as g')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $eventId)
            ->groupBy('g.event_staffing_role')
            ->pluck(DB::raw('count(*)'), 'g.event_staffing_role');

        $rows = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->leftJoin('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->where('r.event', $eventId)
            ->orderBy('r.sequence')
            ->orderBy('r.id')
            ->orderBy('g.group_index')
            ->get([
                'a.volunteer_person',
                'r.id as role_id',
                'r.label as role_label',
                'r.sequence',
                'r.m_role',
                'mr.name as catalog_name',
                'mr.first_program',
                'g.group_index',
                'g.surplus',
            ]);

        $assignmentsByPerson = [];
        foreach ($rows as $row) {
            $personId = (int) $row->volunteer_person;
            $roleLabel = trim((string) ($row->role_label ?: ($row->catalog_name ?: 'Rolle')));
            $groupCount = (int) ($groupCounts[$row->role_id] ?? 1);
            $tileName = ($groupCount <= 1 && ! $row->surplus)
                ? $roleLabel
                : trim($roleLabel.' '.$row->group_index);

            $assignment = [
                'tile_name' => $tileName,
                'label' => $roleLabel,
                'role_id' => (int) $row->role_id,
                'first_program' => $row->m_role ? (($row->first_program !== null) ? (int) $row->first_program : null) : null,
                'is_local' => $row->m_role === null,
                'sequence' => (int) $row->sequence,
                'group_index' => (int) $row->group_index,
            ];

            if (! isset($assignmentsByPerson[$personId])) {
                $assignmentsByPerson[$personId] = [];
            }

            $exists = false;
            foreach ($assignmentsByPerson[$personId] as $existing) {
                if ($existing['tile_name'] === $assignment['tile_name']) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $assignmentsByPerson[$personId][] = $assignment;
            }
        }

        return $assignmentsByPerson;
    }

    private function deleteAssignmentsOnEvent(int $eventId, int $personId): void
    {
        $groupIds = DB::table('event_staffing_group as g')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $eventId)
            ->pluck('g.id');

        if ($groupIds->isEmpty()) {
            return;
        }

        EventStaffingAssignment::query()
            ->where('volunteer_person', $personId)
            ->whereIn('event_staffing_group', $groupIds)
            ->delete();
    }
}
