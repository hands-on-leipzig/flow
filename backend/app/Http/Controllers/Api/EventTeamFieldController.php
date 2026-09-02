<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Support\TeamDataCustomFields;
use App\Support\VolunteerCollectOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventTeamFieldController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $fields = EventTeamField::query()
            ->where('event', $event->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $usageByFieldId = DB::table('event_team_field_value')
            ->whereIn('event_team_field', $fields->pluck('id'))
            ->select('event_team_field', DB::raw('count(*) as usage_count'))
            ->groupBy('event_team_field')
            ->pluck('usage_count', 'event_team_field');

        $payload = $fields->map(function (EventTeamField $field) use ($usageByFieldId) {
            $row = TeamDataCustomFields::serializeField($field);
            $row['usage_count'] = (int) ($usageByFieldId[$field->id] ?? 0);

            return $row;
        });

        return response()->json([
            'fields' => $payload,
            'collect' => [
                'meal' => VolunteerCollectOptions::collectsMeal($event),
            ],
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $count = EventTeamField::query()->where('event', $event->id)->count();
        if ($count >= TeamDataCustomFields::MAX_FIELDS_PER_EVENT) {
            return response()->json([
                'error' => 'Maximal '.TeamDataCustomFields::MAX_FIELDS_PER_EVENT.' Spalten pro Veranstaltung.',
            ], 422);
        }

        $validation = TeamDataCustomFields::validateDefinition($request->all());
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        $sequence = (int) EventTeamField::query()
            ->where('event', $event->id)
            ->max('sequence') + 1;

        $field = EventTeamField::create([
            'event' => $event->id,
            'field_key' => TeamDataCustomFields::slugFromLabel($validation['data']['label'], $event->id),
            'label' => $validation['data']['label'],
            'type' => $validation['data']['type'],
            'options' => $validation['data']['options'],
            'sequence' => $sequence,
            'public_form' => false,
        ]);

        return response()->json([
            'field' => TeamDataCustomFields::serializeField($field),
        ], 201);
    }

    public function update(Request $request, Event $event, EventTeamField $field): JsonResponse
    {
        if ((int) $field->event !== (int) $event->id) {
            return response()->json(['error' => 'Spalte gehört nicht zu dieser Veranstaltung.'], 404);
        }

        if ($request->has('type') && trim((string) $request->input('type')) !== (string) $field->type) {
            return response()->json([
                'error' => 'Der Feldtyp kann nach dem Anlegen nicht mehr geändert werden.',
            ], 422);
        }

        $validation = TeamDataCustomFields::validateDefinition($request->all(), $field);
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
            'field' => TeamDataCustomFields::serializeField($field),
        ]);
    }

    public function destroy(Event $event, EventTeamField $field): JsonResponse
    {
        if ((int) $field->event !== (int) $event->id) {
            return response()->json(['error' => 'Spalte gehört nicht zu dieser Veranstaltung.'], 404);
        }

        DB::transaction(function () use ($field, $event) {
            EventTeamFieldValue::query()->where('event_team_field', $field->id)->delete();
            $field->delete();
            $this->renumberSequences($event->id);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Checklist save: which custom fields appear on the public coach form.
     */
    public function replacePublicForm(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'field_keys' => 'present|array',
            'field_keys.*' => 'string|max:64',
        ]);

        $keys = array_values(array_unique(array_map('strval', $validated['field_keys'])));
        $fields = EventTeamField::query()->where('event', $event->id)->get();
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

        $serialized = EventTeamField::query()
            ->where('event', $event->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (EventTeamField $field) => TeamDataCustomFields::serializeField($field));

        return response()->json(['fields' => $serialized]);
    }

    private function swapSequence(int $eventId, EventTeamField $field, int $direction): void
    {
        $fields = EventTeamField::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->values();

        $index = $fields->search(fn (EventTeamField $item) => $item->id === $field->id);
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
        $fields = EventTeamField::query()
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
