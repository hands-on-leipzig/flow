<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Resolves the "now" pivot used to compare against activity times.
 *
 * Activity start/end are naive Europe/Berlin wall clock values, while
 * config('app.timezone') is UTC — so the pivot must always be built from the
 * Berlin clock, never from a bare now().
 *
 * When the clock falls outside the event window the date is projected onto
 * event day 1 while the real time of day is kept, so previews and day-of tools
 * behave on a test date exactly as they would on the event day.
 */
final class EventDayClock
{
    public const TZ = 'Europe/Berlin';

    /**
     * Berlin wall clock, with the date projected onto the event window.
     */
    public static function pivot(?string $eventDate, int $eventDays = 1): Carbon
    {
        $clock = now(self::TZ);
        if (! $eventDate) {
            return $clock;
        }

        $eventStart = Carbon::createFromFormat('Y-m-d', substr($eventDate, 0, 10), self::TZ)->startOfDay();
        $eventEnd = $eventStart->copy()->addDays(max(1, $eventDays) - 1)->endOfDay();

        if ($clock->betweenIncluded($eventStart, $eventEnd)) {
            return $clock;
        }

        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $eventStart->format('Y-m-d').' '.$clock->format('H:i'),
            self::TZ
        );
    }
}
