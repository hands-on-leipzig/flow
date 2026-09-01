<?php

namespace App\Http\Controllers\Api;

use App\Export\Spreadsheet\SpreadsheetResponse;
use App\Export\Volunteers\VolunteerRosterSpreadsheetSource;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventVolunteerField;
use App\Models\EventVolunteerFieldValue;
use App\Models\EventVolunteerRoster;
use App\Models\EventVolunteerRosterDetail;
use App\Models\VolunteerPerson;
use App\Support\PersonIdsFilter;
use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterColumns;
use App\Support\VolunteerRosterCustomFields;
use App\Support\VolunteerRosterDetailFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EventVolunteerRosterController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $customFields = VolunteerRosterColumns::customFieldsForEvent($event->id);
        $mealOptions = VolunteerMealOptions::bootstrapForEvent($event->id);

        $rows = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->with(['person', 'detail', 'fieldValues.field'])
            ->get()
            ->sortBy(fn (EventVolunteerRoster $row) => [
                mb_strtolower($row->person?->last_name ?? ''),
                mb_strtolower($row->person?->first_name ?? ''),
            ])
            ->values();

        $assignedPersonIds = $this->assignedPersonIds($event->id);
        $assignmentsByPerson = $this->assignmentsByPerson($event->id);

        $roster = $rows->map(function (EventVolunteerRoster $row) use ($assignedPersonIds, $assignmentsByPerson, $customFields) {
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
                'custom' => $this->customValuesForRow($row, $customFields),
                'person' => [
                    'id' => $person->id,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'email' => $person->email,
                    'mobile' => $person->mobile,
                    'organization' => $person->organization,
                    'updated_at' => optional($person->updated_at)?->toIso8601String(),
                ],
            ];
        })->filter()->values();

        return response()->json([
            'roster' => $roster,
            'columns' => VolunteerRosterColumns::tablePayloadForEvent($event->id),
            'meal_options' => $mealOptions,
        ]);
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
            return response()->json(['error' => 'Person ist nicht auf der Helfer:innenliste.'], 404);
        }

        $existing = $roster->detail;
        $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        if ($mealOptions->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($event->id);
            $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        }

        $payload = [
            't_shirt_cut' => $request->input('t_shirt_cut', $existing?->t_shirt_cut),
            't_shirt_size' => $request->input('t_shirt_size', $existing?->t_shirt_size),
            'meal' => $request->input('meal', $existing?->meal),
            'photo_consent' => $request->has('photo_consent') ? $request->input('photo_consent') : $existing?->photo_consent,
            'notes' => $request->has('notes') ? $request->input('notes') : $existing?->notes,
        ];

        $validation = VolunteerRosterDetailFields::validate(
            $payload,
            VolunteerMealOptions::allowedValues($mealOptions),
        );
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

    public function updateCustom(Request $request, Event $event, VolunteerPerson $volunteer): JsonResponse
    {
        if ((int) $volunteer->regional_partner !== (int) $event->regional_partner) {
            return response()->json(['error' => 'Person gehört nicht zu diesem Regionalpartner.'], 422);
        }

        $roster = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $volunteer->id)
            ->first();

        if (! $roster) {
            return response()->json(['error' => 'Person ist nicht auf der Helfer:innenliste.'], 404);
        }

        $validated = $request->validate([
            'field_key' => 'required|string|max:64',
            'value' => 'nullable',
        ]);

        $field = EventVolunteerField::query()
            ->where('event', $event->id)
            ->where('field_key', $validated['field_key'])
            ->first();

        if (! $field) {
            return response()->json(['error' => 'Spalte nicht gefunden.'], 404);
        }

        $validation = VolunteerRosterCustomFields::validateValue($field, $validated['value']);
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        if ($validation['stored'] === null) {
            EventVolunteerFieldValue::query()
                ->where('event_volunteer_roster', $roster->id)
                ->where('event_volunteer_field', $field->id)
                ->delete();
        } else {
            EventVolunteerFieldValue::query()->updateOrCreate(
                [
                    'event_volunteer_roster' => $roster->id,
                    'event_volunteer_field' => $field->id,
                ],
                [
                    'value' => $validation['stored'],
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'custom' => [
                $field->field_key => $validation['api'],
            ],
        ]);
    }

    public function exportXlsx(Request $request, Event $event): Response
    {
        return SpreadsheetResponse::download(
            (new VolunteerRosterSpreadsheetSource(
                $event,
                PersonIdsFilter::parse($request),
            ))->document()
        );
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
     * @param  Collection<int, EventVolunteerField>  $customFields
     * @return array<string, mixed>
     */
    private function customValuesForRow(EventVolunteerRoster $row, Collection $customFields): array
    {
        $valuesByFieldId = $row->fieldValues->keyBy('event_volunteer_field');
        $payload = [];

        foreach ($customFields as $field) {
            $stored = $valuesByFieldId->get($field->id)?->value;
            $payload[$field->field_key] = VolunteerRosterCustomFields::apiValue($field, $stored);
        }

        return $payload;
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
