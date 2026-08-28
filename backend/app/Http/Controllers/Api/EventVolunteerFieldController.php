<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventVolunteerField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\VolunteerRosterCustomFields;

class EventVolunteerFieldController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $fields = EventVolunteerField::query()
            ->where('event', $event->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (EventVolunteerField $field) => VolunteerRosterCustomFields::serializeField($field));

        return response()->json(['fields' => $fields]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $count = EventVolunteerField::query()->where('event', $event->id)->count();
        if ($count >= VolunteerRosterCustomFields::MAX_FIELDS_PER_EVENT) {
            return response()->json([
                'error' => 'Maximal '.VolunteerRosterCustomFields::MAX_FIELDS_PER_EVENT.' Spalten pro Veranstaltung.',
            ], 422);
        }

        $validation = VolunteerRosterCustomFields::validateDefinition($request->all());
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        $sequence = (int) EventVolunteerField::query()
            ->where('event', $event->id)
            ->max('sequence') + 1;

        $field = EventVolunteerField::create([
            'event' => $event->id,
            'field_key' => VolunteerRosterCustomFields::slugFromLabel($validation['data']['label'], $event->id),
            'label' => $validation['data']['label'],
            'type' => $validation['data']['type'],
            'options' => $validation['data']['options'],
            'sequence' => $sequence,
        ]);

        return response()->json([
            'field' => VolunteerRosterCustomFields::serializeField($field),
        ], 201);
    }

    public function update(Request $request, Event $event, EventVolunteerField $field): JsonResponse
    {
        if ((int) $field->event !== (int) $event->id) {
            return response()->json(['error' => 'Spalte gehört nicht zu dieser Veranstaltung.'], 404);
        }

        $validation = VolunteerRosterCustomFields::validateDefinition($request->all(), $field);
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        $field->fill([
            'label' => $validation['data']['label'],
            'type' => $validation['data']['type'],
            'options' => $validation['data']['options'],
        ]);

        if ($request->has('sequence')) {
            $field->sequence = max(0, (int) $request->input('sequence'));
        }

        $field->save();

        if ($request->boolean('move_up')) {
            $this->swapSequence($event->id, $field, -1);
        } elseif ($request->boolean('move_down')) {
            $this->swapSequence($event->id, $field, 1);
        }

        $field->refresh();

        return response()->json([
            'field' => VolunteerRosterCustomFields::serializeField($field),
        ]);
    }

    public function destroy(Event $event, EventVolunteerField $field): JsonResponse
    {
        if ((int) $field->event !== (int) $event->id) {
            return response()->json(['error' => 'Spalte gehört nicht zu dieser Veranstaltung.'], 404);
        }

        DB::transaction(function () use ($field, $event) {
            $field->delete();
            $this->renumberSequences($event->id);
        });

        return response()->json(['ok' => true]);
    }

    private function swapSequence(int $eventId, EventVolunteerField $field, int $direction): void
    {
        $fields = EventVolunteerField::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->values();

        $index = $fields->search(fn (EventVolunteerField $item) => $item->id === $field->id);
        if ($index === false) {
            return;
        }

        $targetIndex = $index + $direction;
        if ($targetIndex < 0 || $targetIndex >= $fields->count()) {
            return;
        }

        $other = $fields[$targetIndex];
        $currentSequence = (int) $field->sequence;
        $field->sequence = (int) $other->sequence;
        $other->sequence = $currentSequence;
        $field->save();
        $other->save();
    }

    private function renumberSequences(int $eventId): void
    {
        $fields = EventVolunteerField::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        foreach ($fields as $index => $field) {
            if ((int) $field->sequence !== $index + 1) {
                $field->sequence = $index + 1;
                $field->save();
            }
        }
    }
}
