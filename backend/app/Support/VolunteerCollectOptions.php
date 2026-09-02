<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

final class VolunteerCollectOptions
{
    /**
     * @return array{t_shirt: bool, meal: bool}
     */
    public static function forEvent(Event $event): array
    {
        return [
            't_shirt' => (bool) ($event->volunteer_collect_t_shirt ?? true),
            'meal' => (bool) ($event->volunteer_collect_meal ?? true),
        ];
    }

    public static function collectsTShirt(Event $event): bool
    {
        return (bool) ($event->volunteer_collect_t_shirt ?? true);
    }

    public static function collectsMeal(Event $event): bool
    {
        return (bool) ($event->volunteer_collect_meal ?? true);
    }

    /**
     * @return array{t_shirt_cleared: int, meal_cleared: int}
     */
    public static function apply(Event $event, ?bool $tShirt, ?bool $meal): array
    {
        $cleared = ['t_shirt_cleared' => 0, 'meal_cleared' => 0];

        DB::transaction(function () use ($event, $tShirt, $meal, &$cleared) {
            $updates = [];
            if ($tShirt !== null) {
                $wasOn = self::collectsTShirt($event);
                $updates['volunteer_collect_t_shirt'] = $tShirt;
                $event->volunteer_collect_t_shirt = $tShirt;
                if ($wasOn && ! $tShirt) {
                    $cleared['t_shirt_cleared'] = self::clearTShirtForEvent($event->id);
                }
            }
            if ($meal !== null) {
                $wasOn = self::collectsMeal($event);
                $updates['volunteer_collect_meal'] = $meal;
                $event->volunteer_collect_meal = $meal;
                if ($wasOn && ! $meal) {
                    $cleared['meal_cleared'] = self::clearMealForEvent($event->id);
                }
            }
            if ($updates !== []) {
                DB::table('event')->where('id', $event->id)->update($updates);
            }
        });

        return $cleared;
    }

    public static function usageCountTShirt(int $eventId): int
    {
        return (int) DB::table('event_volunteer_roster_detail as d')
            ->join('event_volunteer_roster as r', 'r.id', '=', 'd.event_volunteer_roster')
            ->where('r.event', $eventId)
            ->where(function ($q) {
                $q->whereNotNull('d.t_shirt_cut')->orWhereNotNull('d.t_shirt_size');
            })
            ->count();
    }

    public static function usageCountMeal(int $eventId): int
    {
        return (int) DB::table('event_volunteer_roster_detail as d')
            ->join('event_volunteer_roster as r', 'r.id', '=', 'd.event_volunteer_roster')
            ->where('r.event', $eventId)
            ->whereNotNull('d.meal')
            ->count();
    }

    private static function clearTShirtForEvent(int $eventId): int
    {
        $rosterIds = DB::table('event_volunteer_roster')->where('event', $eventId)->pluck('id');
        if ($rosterIds->isEmpty()) {
            return 0;
        }

        return DB::table('event_volunteer_roster_detail')
            ->whereIn('event_volunteer_roster', $rosterIds)
            ->where(function ($q) {
                $q->whereNotNull('t_shirt_cut')->orWhereNotNull('t_shirt_size');
            })
            ->update([
                't_shirt_cut' => null,
                't_shirt_size' => null,
                'updated_at' => now(),
            ]);
    }

    private static function clearMealForEvent(int $eventId): int
    {
        $rosterIds = DB::table('event_volunteer_roster')->where('event', $eventId)->pluck('id');
        if ($rosterIds->isEmpty()) {
            return 0;
        }

        return DB::table('event_volunteer_roster_detail')
            ->whereIn('event_volunteer_roster', $rosterIds)
            ->whereNotNull('meal')
            ->update([
                'meal' => null,
                'updated_at' => now(),
            ]);
    }
}
