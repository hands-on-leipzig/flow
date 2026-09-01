<?php

namespace App\Support;

/**
 * Normalizes DRAHT /handson/events/{id}/scheduledata payloads for FLOW consumers.
 */
final class DrahtScheduleData
{
    /**
     * @return array{
     *     scheduledata: array<string, mixed>|null,
     *     teams: array<int|string, mixed>,
     *     capacity: int,
     *     program_gone: bool
     * }
     */
    public static function normalize(?array $payload): array
    {
        if ($payload === null) {
            return [
                'scheduledata' => null,
                'teams' => [],
                'capacity' => 0,
                'program_gone' => false,
            ];
        }

        $teamsRaw = $payload['teams'] ?? [];
        $teamsValid = is_array($teamsRaw);
        $teams = $teamsValid ? $teamsRaw : [];

        $capacityRaw = $payload['capacity_teams'] ?? null;
        $capacity = $capacityRaw !== null && $capacityRaw !== '' ? (int) $capacityRaw : 0;

        $eventId = $payload['id'] ?? null;
        $hasEventId = $eventId !== null && $eventId !== '' && (int) $eventId > 0;

        $programGone = ! $teamsValid
            || ! $hasEventId
            || $capacityRaw === null
            || $capacity === 0;

        return [
            'scheduledata' => $payload,
            'teams' => $teams,
            'capacity' => max(0, $capacity),
            'program_gone' => $programGone,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $teams
     * @return list<array{team_number_hot: int, organization: ?string, location: ?string}>
     */
    public static function teamDetailRows(array $teams): array
    {
        $items = array_is_list($teams) ? $teams : array_values($teams);
        $rows = [];

        foreach ($items as $team) {
            if (! is_array($team)) {
                continue;
            }

            $number = TeamSyncMatcher::normalizeTeamNumber($team['ref'] ?? $team['number'] ?? $team['team_number_hot'] ?? null);
            if ($number === null) {
                continue;
            }

            $rows[] = [
                'team_number_hot' => $number,
                'organization' => self::nullableString($team['organization'] ?? null),
                'location' => self::nullableString($team['location'] ?? null),
            ];
        }

        return $rows;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
