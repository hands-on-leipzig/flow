<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventVolunteerRosterController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $rows = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->with('person')
            ->get()
            ->sortBy(fn (EventVolunteerRoster $row) => [
                mb_strtolower($row->person?->last_name ?? ''),
                mb_strtolower($row->person?->first_name ?? ''),
            ])
            ->values();

        $assignedPersonIds = $this->assignedPersonIds($event->id);

        $roster = $rows->map(function (EventVolunteerRoster $row) use ($assignedPersonIds) {
            $person = $row->person;
            if (! $person) {
                return null;
            }

            return [
                'id' => $row->id,
                'event' => $row->event,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'has_assignment' => in_array($person->id, $assignedPersonIds, true),
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

    public function destroy(Event $event, VolunteerPerson $volunteer): JsonResponse
    {
        if ((int) $volunteer->regional_partner !== (int) $event->regional_partner) {
            return response()->json(['error' => 'Person gehört nicht zu diesem Regionalpartner.'], 422);
        }

        if ($this->personHasAssignmentOnEvent($event->id, $volunteer->id)) {
            return response()->json([
                'error' => 'Person ist noch besetzt und kann nicht von der Anmeldung entfernt werden.',
            ], 409);
        }

        EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $volunteer->id)
            ->delete();

        return response()->json(['ok' => true]);
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

    private function personHasAssignmentOnEvent(int $eventId, int $personId): bool
    {
        return DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $eventId)
            ->where('a.volunteer_person', $personId)
            ->exists();
    }
}
