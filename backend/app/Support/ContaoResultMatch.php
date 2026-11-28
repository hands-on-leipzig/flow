<?php

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * Which Contao season and competition belong to one FLOW event.
 *
 * A stored contao id is the competition of whatever year DRAHT last sent.
 * The public page only follows it when that competition sits in the event's
 * own season. Otherwise the same competition name is looked up there.
 */
class ContaoResultMatch
{
    public static function seasonLabel(?int $year): ?string
    {
        if ($year === null || $year < 2000) {
            return null;
        }

        return $year.'/'.substr((string) ($year + 1), -2);
    }

    public static function seasonType(FirstProgram $program): ?string
    {
        return match ($program) {
            FirstProgram::DISCOVER, FirstProgram::EXPLORE => 'FLL Junior',
            FirstProgram::CHALLENGE => 'FLL',
            default => null,
        };
    }

    /**
     * @param  list<array{id: int, season_start: mixed, season_end: mixed, use_for_evaluation_tool: mixed}>  $seasons
     */
    public static function pickSeasonId(array $seasons, ?string $eventDate): ?int
    {
        if ($seasons === []) {
            return null;
        }

        $moment = self::eventMoment($eventDate);
        $containing = [];
        if ($moment !== null) {
            foreach ($seasons as $season) {
                if (self::contains($season, $moment)) {
                    $containing[] = $season;
                }
            }
        }

        $pool = $containing !== [] ? $containing : $seasons;
        usort($pool, function (array $a, array $b): int {
            $flag = self::flag($b) <=> self::flag($a);
            if ($flag !== 0) {
                return $flag;
            }

            return (int) $b['id'] <=> (int) $a['id'];
        });

        return (int) $pool[0]['id'];
    }

    /**
     * @param  array{id: int, name: string, season: int}|null  $stored
     * @param  list<array{id: int, name: string}>  $inSeason
     */
    public static function pickRegionId(?array $stored, int $seasonId, array $inSeason): ?int
    {
        if ($stored !== null && (int) $stored['season'] === $seasonId) {
            return (int) $stored['id'];
        }

        if ($stored === null) {
            return null;
        }

        $name = self::normalizeName($stored['name']);
        if ($name === '') {
            return null;
        }

        $ids = [];
        foreach ($inSeason as $row) {
            if (self::normalizeName($row['name']) === $name) {
                $ids[] = (int) $row['id'];
            }
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 1 ? $ids[0] : null;
    }

    public static function normalizeName(string $name): string
    {
        $decoded = html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? $decoded;

        return mb_strtolower($collapsed);
    }

    private static function eventMoment(?string $eventDate): ?int
    {
        if ($eventDate === null || trim($eventDate) === '') {
            return null;
        }

        $moment = strtotime(substr($eventDate, 0, 10).' 12:00:00');

        return $moment === false ? null : $moment;
    }

    /**
     * @param  array{season_start: mixed, season_end: mixed}  $season
     */
    private static function contains(array $season, int $moment): bool
    {
        $start = (int) $season['season_start'];
        $end = (int) $season['season_end'];
        if ($start <= 0 || $end <= 0) {
            return false;
        }

        return $moment >= $start && $moment <= $end;
    }

    /**
     * @param  array{use_for_evaluation_tool: mixed}  $season
     */
    private static function flag(array $season): int
    {
        return (string) $season['use_for_evaluation_tool'] === '1' ? 1 : 0;
    }
}
