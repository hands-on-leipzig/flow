<?php

namespace App\Support;

use App\Models\EventTeamMealCount;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TeamMealCounts
{
    /**
     * @return array<string, int>
     */
    public static function mapForTeam(int $teamId): array
    {
        return EventTeamMealCount::query()
            ->where('team', $teamId)
            ->pluck('count', 'meal_value')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public static function mapForTeamWithCatalog(int $teamId, int $eventId): array
    {
        $options = VolunteerMealOptions::optionsForEvent($eventId);
        if ($options->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($eventId);
            $options = VolunteerMealOptions::optionsForEvent($eventId);
        }

        $stored = self::mapForTeam($teamId);
        $result = [];
        foreach ($options as $option) {
            $result[$option->value] = (int) ($stored[$option->value] ?? 0);
        }

        return $result;
    }

    public static function isTouched(int $teamId): bool
    {
        return EventTeamMealCount::query()->where('team', $teamId)->exists();
    }

    /**
     * @param  array<string, int>  $counts keyed by meal_value
     */
    public static function replaceForTeam(int $teamId, int $eventId, array $counts): void
    {
        $options = VolunteerMealOptions::optionsForEvent($eventId);
        if ($options->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($eventId);
            $options = VolunteerMealOptions::optionsForEvent($eventId);
        }

        $allowed = $options->pluck('value')->all();
        $now = now();

        foreach ($allowed as $mealValue) {
            if (! array_key_exists($mealValue, $counts)) {
                throw new \InvalidArgumentException('Missing meal count for '.$mealValue);
            }
            $count = (int) $counts[$mealValue];
            if ($count < 0) {
                throw new \InvalidArgumentException('Invalid meal count for '.$mealValue);
            }

            EventTeamMealCount::query()->updateOrCreate(
                ['team' => $teamId, 'meal_value' => $mealValue],
                ['count' => $count, 'updated_at' => $now],
            );
        }
    }

    public static function usageCountForEvent(int $eventId): int
    {
        if (! Schema::hasTable('team') || ! Schema::hasTable('event_team_meal_count')) {
            return 0;
        }

        $teamIds = Team::query()->where('event', $eventId)->pluck('id');
        if ($teamIds->isEmpty()) {
            return 0;
        }

        return (int) EventTeamMealCount::query()
            ->whereIn('team', $teamIds)
            ->where('count', '>', 0)
            ->count();
    }

    public static function clearForEvent(int $eventId): int
    {
        if (! Schema::hasTable('team') || ! Schema::hasTable('event_team_meal_count')) {
            return 0;
        }

        $teamIds = Team::query()->where('event', $eventId)->pluck('id');
        if ($teamIds->isEmpty()) {
            return 0;
        }

        return EventTeamMealCount::query()->whereIn('team', $teamIds)->delete();
    }

    public static function sumForTeam(int $teamId): int
    {
        return (int) EventTeamMealCount::query()->where('team', $teamId)->sum('count');
    }
}
