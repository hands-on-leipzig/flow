<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventVolunteerField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\VolunteerCollectOptions;
use App\Support\VolunteerRosterCustomFields;

class EventVolunteerFieldController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $fields = EventVolunteerField::query()
            ->where('event', $event->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $usageByFieldId = DB::table('event_volunteer_field_value')
            ->whereIn('event_volunteer_field', $fields->pluck('id'))
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->select('event_volunteer_field', DB::raw('count(*) as usage_count'))
            ->groupBy('event_volunteer_field')
            ->pluck('usage_count', 'event_volunteer_field');

        $payload = $fields->map(function (EventVolunteerField $field) use ($usageByFieldId) {
            $row = VolunteerRosterCustomFields::serializeField($field);
            $row['usage_count'] = (int) ($usageByFieldId[$field->id] ?? 0);

            return $row;
        });

        return response()->json([
            'fields' => $payload,
            'collect' => VolunteerCollectOptions::forEvent($event),
        ]);
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
            'public_form' => false,
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

        if ($request->has('type') && trim((string) $request->input('type')) !== (string) $field->type) {
            return response()->json([
                'error' => 'Der Feldtyp kann nach dem Anlegen nicht mehr geändert werden.',
            ], 422);
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

    /**
     * Checklist save: which custom fields appear on the public form.
     */
    public function replacePublicForm(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'field_keys' => 'present|array',
            'field_keys.*' => 'string|max:64',
        ]);

        $keys = array_values(array_unique(array_map('strval', $validated['field_keys'])));
        $fields = EventVolunteerField::query()->where('event', $event->id)->get();
        $known = $fields->pluck('field_key')->all();

        foreach ($keys as $key) {
            if (! in_array($key, $known, true)) {
                return response()->json(['error' => 'Unbekanntes Zusatzfeld.'], 422);
            }
        }

        DB::transaction(function () use ($fields, $keys) {
            foreach ($fields as $field) {
                $next = in_array($field->field_key, $keys, true);
                if ((bool) $field->public_form !== $next) {
                    $field->public_form = $next;
                    $field->save();
                }
            }
        });

        $serialized = EventVolunteerField::query()
            ->where('event', $event->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (EventVolunteerField $field) => VolunteerRosterCustomFields::serializeField($field));

        return response()->json(['fields' => $serialized]);
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
