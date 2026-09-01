<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventVolunteerMealOption;
use App\Support\VolunteerMealOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventVolunteerMealOptionController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        VolunteerMealOptions::bootstrapForEvent($event->id);
        $options = VolunteerMealOptions::optionsForEvent($event->id);

        return response()->json([
            'options' => VolunteerMealOptions::serializeList($options, $event->id),
        ]);
    }

    public function replace(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'options' => 'required|array|min:1',
            'options.*.value' => 'nullable|string|max:64',
            'options.*.label' => 'required|string|max:120',
        ]);

        $validation = VolunteerMealOptions::validateReplaceList($validated['options']);
        if (! $validation['ok']) {
            return response()->json(['error' => $validation['error']], 422);
        }

        $current = VolunteerMealOptions::optionsForEvent($event->id);
        if ($current->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($event->id);
            $current = VolunteerMealOptions::optionsForEvent($event->id);
        }

        $newValues = array_column($validation['data'], 'value');
        $removed = $current->pluck('value')->diff($newValues)->values()->all();

        DB::transaction(function () use ($event, $validation, $removed) {
            VolunteerMealOptions::clearAssignmentsForRemovedValues($event->id, $removed);

            EventVolunteerMealOption::query()->where('event', $event->id)->delete();

            foreach ($validation['data'] as $index => $option) {
                EventVolunteerMealOption::create([
                    'event' => $event->id,
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'sequence' => $index + 1,
                ]);
            }
        });

        return response()->json([
            'options' => VolunteerMealOptions::serializeList(
                VolunteerMealOptions::optionsForEvent($event->id),
                $event->id,
            ),
        ]);
    }
}
