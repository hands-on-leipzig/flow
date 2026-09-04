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
use App\Support\StaffingAssignmentLabel;
use App\Support\VolunteerCollectOptions;
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
                'custom' => VolunteerRosterCustomFields::apiValuesForRow($row, $customFields),
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
            'collect' => VolunteerCollectOptions::forEvent($event),
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

        $collectShirt = VolunteerCollectOptions::collectsTShirt($event);
        $collectMeal = VolunteerCollectOptions::collectsMeal($event);

        $payload = [
            't_shirt_cut' => $collectShirt
                ? $request->input('t_shirt_cut', $existing?->t_shirt_cut)
                : null,
            't_shirt_size' => $collectShirt
                ? $request->input('t_shirt_size', $existing?->t_shirt_size)
                : null,
            'meal' => $collectMeal
                ? $request->input('meal', $existing?->meal)
                : null,
            'photo_consent' => $request->has('photo_consent') ? $request->input('photo_consent') : $existing?->photo_consent,
        ];

        if (! $collectShirt && ($request->has('t_shirt_cut') || $request->has('t_shirt_size'))) {
            return response()->json(['error' => 'T-Shirt-Angaben sind für diese Veranstaltung deaktiviert.'], 422);
        }
        if (! $collectMeal && $request->has('meal')) {
            return response()->json(['error' => 'Essenswahl ist für diese Veranstaltung deaktiviert.'], 422);
        }

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

        $fieldsInput = $request->input('fields');
        if (is_array($fieldsInput)) {
            $updates = $fieldsInput;
        } elseif ($request->has('field_key')) {
            $validated = $request->validate([
                'field_key' => 'required|string|max:64',
                'value' => 'nullable',
            ]);
            $updates = [$validated['field_key'] => $validated['value']];
        } else {
            return response()->json(['error' => 'Keine Felder angegeben.'], 422);
        }

        if ($updates === []) {
            return response()->json(['error' => 'Keine Felder angegeben.'], 422);
        }

        $eventFields = EventVolunteerField::query()
            ->where('event', $event->id)
            ->get()
            ->keyBy('field_key');

        foreach (array_keys($updates) as $fieldKey) {
            if (! is_string($fieldKey) || ! $eventFields->has($fieldKey)) {
                return response()->json(['error' => 'Unbekanntes Zusatzfeld.'], 422);
            }
        }

        $validatedUpdates = [];
        foreach ($updates as $fieldKey => $value) {
            /** @var EventVolunteerField $field */
            $field = $eventFields->get($fieldKey);
            $validation = VolunteerRosterCustomFields::validateValue($field, $value);
            if (! $validation['ok']) {
                return response()->json(['error' => $validation['error']], 422);
            }
            $validatedUpdates[$fieldKey] = [
                'field' => $field,
                'stored' => $validation['stored'],
            ];
        }

        DB::transaction(function () use ($roster, $validatedUpdates) {
            foreach ($validatedUpdates as $entry) {
                /** @var EventVolunteerField $field */
                $field = $entry['field'];
                if ($entry['stored'] === null) {
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
                            'value' => $entry['stored'],
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });

        $roster->unsetRelation('fieldValues');
        $roster->load('fieldValues.field');
        $customFields = VolunteerRosterColumns::customFieldsForEvent($event->id);

        return response()->json([
            'custom' => VolunteerRosterCustomFields::apiValuesForRow($roster, $customFields),
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
     * @return list<int>
     */
    private function assignedPersonIds(int $eventId): array
    {
        return DB::table('event_staffing_assignment as a')
            ->join('event_staffing_role as r', 'r.id', '=', 'a.event_staffing_role')
            ->where('r.event', $eventId)
            ->pluck('a.volunteer_person')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, list<array{tile_name: string, caption: string, label: string, role_id: int, first_program: ?int, is_local: bool, sequence: int, group_index: ?int, group_label: ?string}>>
     */
    private function assignmentsByPerson(int $eventId): array
    {
        return StaffingAssignmentLabel::assignmentsByPerson($eventId);
    }

    private function deleteAssignmentsOnEvent(int $eventId, int $personId): void
    {
        $roleIds = DB::table('event_staffing_role')
            ->where('event', $eventId)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        EventStaffingAssignment::query()
            ->where('volunteer_person', $personId)
            ->whereIn('event_staffing_role', $roleIds)
            ->delete();
    }
}
