<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\VolunteerCollectOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventVolunteerCollectController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        $flags = VolunteerCollectOptions::forEvent($event);

        return response()->json([
            'collect' => $flags,
            'usage' => [
                't_shirt' => VolunteerCollectOptions::usageCountTShirt($event->id),
                'meal' => VolunteerCollectOptions::usageCountMeal($event->id),
            ],
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        // Use exists(), not has() — has() treats false as missing.
        $tShirt = $request->exists('t_shirt') ? $request->boolean('t_shirt') : null;
        $meal = $request->exists('meal') ? $request->boolean('meal') : null;

        if ($tShirt === null && $meal === null) {
            return response()->json(['error' => 'Keine Einstellung angegeben.'], 422);
        }

        $cleared = VolunteerCollectOptions::apply($event, $tShirt, $meal);
        $event->refresh();

        return response()->json([
            'collect' => VolunteerCollectOptions::forEvent($event),
            'cleared' => $cleared,
            'usage' => [
                't_shirt' => VolunteerCollectOptions::usageCountTShirt($event->id),
                'meal' => VolunteerCollectOptions::usageCountMeal($event->id),
            ],
        ]);
    }
}
