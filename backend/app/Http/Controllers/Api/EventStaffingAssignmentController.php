<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventStaffingGroup;
use App\Models\EventStaffingRole;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventStaffingAssignmentController extends Controller
{
    public function store(Request $request, Event $event, EventStaffingGroup $group): JsonResponse
    {
        $this->assertGroupOnEvent($event, $group);
        $role = $group->staffingRole;

        if (! $role->isGrouped()) {
            return response()->json(['error' => 'Gruppe gehört nicht zu einer gruppierten Rolle.'], 422);
        }

        if ($group->surplus) {
            return response()->json(['error' => 'Surplus-Gruppe: nur Entfernen erlaubt.'], 409);
        }

        $personId = $this->validatedPersonOnRoster($request, $event);
        if ($personId instanceof JsonResponse) {
            return $personId;
        }

        $filled = EventStaffingAssignment::query()
            ->where('event_staffing_group', $group->id)
            ->count();
        if ($filled >= (int) $role->max) {
            return response()->json(['error' => 'Maximum für diese Gruppe erreicht.'], 409);
        }

        if ($conflict = $this->exclusivityConflict($event->id, $personId, $role)) {
            return response()->json(['error' => $conflict], 409);
        }

        $assignment = EventStaffingAssignment::firstOrCreate(
            [
                'event_staffing_role' => $role->id,
                'volunteer_person' => $personId,
            ],
            [
                'event_staffing_group' => $group->id,
                'created_at' => now(),
            ]
        );

        return $this->assignmentResponse($assignment);
    }

    public function destroy(Event $event, EventStaffingGroup $group, VolunteerPerson $volunteer): JsonResponse
    {
        $this->assertGroupOnEvent($event, $group);

        EventStaffingAssignment::query()
            ->where('event_staffing_group', $group->id)
            ->where('volunteer_person', $volunteer->id)
            ->delete();

        $group->refresh();
        if ($group->surplus && ! $group->assignments()->exists()) {
            $group->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function storeOnRole(Request $request, Event $event, EventStaffingRole $role): JsonResponse
    {
        $this->assertRoleOnEvent($event, $role);

        if ($role->isGrouped()) {
            return response()->json(['error' => 'Gruppierte Rolle: Zuweisung nur in eine Gruppe.'], 422);
        }

        if ($role->surplus) {
            return response()->json(['error' => 'Überzählige Rolle: nur Entfernen erlaubt.'], 409);
        }

        $personId = $this->validatedPersonOnRoster($request, $event);
        if ($personId instanceof JsonResponse) {
            return $personId;
        }

        $filled = EventStaffingAssignment::query()
            ->where('event_staffing_role', $role->id)
            ->whereNull('event_staffing_group')
            ->count();
        if ($filled >= (int) $role->max) {
            return response()->json(['error' => 'Maximum für diese Rolle erreicht.'], 409);
        }

        if ($conflict = $this->exclusivityConflict($event->id, $personId, $role)) {
            return response()->json(['error' => $conflict], 409);
        }

        $assignment = EventStaffingAssignment::firstOrCreate(
            [
                'event_staffing_role' => $role->id,
                'volunteer_person' => $personId,
            ],
            [
                'event_staffing_group' => null,
                'created_at' => now(),
            ]
        );

        return $this->assignmentResponse($assignment);
    }

    public function destroyOnRole(Event $event, EventStaffingRole $role, VolunteerPerson $volunteer): JsonResponse
    {
        $this->assertRoleOnEvent($event, $role);

        if ($role->isGrouped()) {
            return response()->json(['error' => 'Gruppierte Rolle: Zuweisung nur in eine Gruppe.'], 422);
        }

        EventStaffingAssignment::query()
            ->where('event_staffing_role', $role->id)
            ->whereNull('event_staffing_group')
            ->where('volunteer_person', $volunteer->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function storeLocalRole(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:150',
            'min' => 'required|integer|min:1',
            'best' => 'required|integer|min:1',
            'max' => 'required|integer|min:1',
            'ui_description' => 'nullable|string',
        ]);

        if ($validated['min'] > $validated['best'] || $validated['best'] > $validated['max']) {
            return response()->json(['error' => 'Es muss min ≤ best ≤ max gelten.'], 422);
        }

        $maxSeq = (int) EventStaffingRole::query()->where('event', $event->id)->max('sequence');

        $role = EventStaffingRole::create([
            'event' => $event->id,
            'm_role' => null,
            'label' => $validated['label'],
            'group_label' => null,
            'min' => $validated['min'],
            'best' => $validated['best'],
            'max' => $validated['max'],
            'ui_description' => $validated['ui_description'] ?? null,
            'sequence' => $maxSeq + 10,
            'surplus' => false,
        ]);

        return response()->json(['role' => ['id' => $role->id, 'label' => $role->label]], 201);
    }

    public function updateLocalRole(Request $request, Event $event, EventStaffingRole $role): JsonResponse
    {
        if ((int) $role->event !== (int) $event->id || $role->m_role !== null) {
            return response()->json(['error' => 'Nur lokale Rollen können so bearbeitet werden.'], 422);
        }

        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:150',
            'min' => 'sometimes|required|integer|min:1',
            'best' => 'sometimes|required|integer|min:1',
            'max' => 'sometimes|required|integer|min:1',
            'ui_description' => 'nullable|string',
        ]);

        $min = $validated['min'] ?? $role->min;
        $best = $validated['best'] ?? $role->best;
        $max = $validated['max'] ?? $role->max;
        if ($min > $best || $best > $max) {
            return response()->json(['error' => 'Es muss min ≤ best ≤ max gelten.'], 422);
        }

        $role->fill($validated);
        $role->save();

        return response()->json(['ok' => true]);
    }

    public function destroyLocalRole(Event $event, EventStaffingRole $role): JsonResponse
    {
        if ((int) $role->event !== (int) $event->id || $role->m_role !== null) {
            return response()->json(['error' => 'Nur lokale Rollen können gelöscht werden.'], 422);
        }

        $hasPeople = EventStaffingAssignment::query()
            ->where('event_staffing_role', $role->id)
            ->exists();

        if ($hasPeople) {
            return response()->json(['error' => 'Rolle hat noch Zuweisungen.'], 409);
        }

        $role->groups()->delete();
        $role->delete();

        return response()->json(['ok' => true]);
    }

    private function assertGroupOnEvent(Event $event, EventStaffingGroup $group): void
    {
        $group->loadMissing('staffingRole');
        if (! $group->staffingRole || (int) $group->staffingRole->event !== (int) $event->id) {
            abort(404, 'Gruppe nicht gefunden');
        }
    }

    private function assertRoleOnEvent(Event $event, EventStaffingRole $role): void
    {
        if ((int) $role->event !== (int) $event->id) {
            abort(404, 'Rolle nicht gefunden');
        }
    }

    private function validatedPersonOnRoster(Request $request, Event $event): int|JsonResponse
    {
        $validated = $request->validate([
            'volunteer_person' => 'required|integer|exists:volunteer_person,id',
        ]);

        $personId = (int) $validated['volunteer_person'];
        $person = VolunteerPerson::findOrFail($personId);

        if ((int) $person->regional_partner !== (int) $event->regional_partner) {
            return response()->json(['error' => 'Person gehört nicht zum Regionalpartner.'], 422);
        }

        if (! EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $personId)
            ->exists()) {
            return response()->json(['error' => 'Person ist nicht auf der Anmeldung.'], 422);
        }

        return $personId;
    }

    private function assignmentResponse(EventStaffingAssignment $assignment): JsonResponse
    {
        return response()->json([
            'assignment' => [
                'id' => $assignment->id,
                'event_staffing_role' => $assignment->event_staffing_role,
                'event_staffing_group' => $assignment->event_staffing_group,
                'volunteer_person' => $assignment->volunteer_person,
            ],
        ], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    private function exclusivityConflict(int $eventId, int $personId, EventStaffingRole $targetRole): ?string
    {
        $existing = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_role as r', 'r.id', '=', 'a.event_staffing_role')
            ->where('r.event', $eventId)
            ->where('a.volunteer_person', $personId)
            ->get(['r.id as role_id', 'r.m_role']);

        if ($existing->isEmpty()) {
            return null;
        }

        $onCatalog = $existing->contains(fn ($row) => $row->m_role !== null);
        $targetIsCatalog = $targetRole->m_role !== null;

        if ($onCatalog) {
            return 'Person hat bereits eine Katalog-Rolle (exklusiv).';
        }

        if ($targetIsCatalog) {
            return 'Person hat bereits lokale Einsätze; zuerst entfernen oder Katalog-Rolle anders besetzen.';
        }

        return null;
    }
}
