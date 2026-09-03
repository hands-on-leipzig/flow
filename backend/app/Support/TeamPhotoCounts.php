<?php

namespace App\Support;

use App\Models\EventTeamPhotoCount;

final class TeamPhotoCounts
{
    public const BUCKETS = ['unknown', 'yes', 'no'];

    /**
     * @return array<string, int>
     */
    public static function mapForTeam(int $teamId): array
    {
        return EventTeamPhotoCount::query()
            ->where('team', $teamId)
            ->pluck('count', 'bucket')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public static function mapForTeamWithDefaults(int $teamId): array
    {
        $stored = self::mapForTeam($teamId);
        $result = [];
        foreach (self::BUCKETS as $bucket) {
            $result[$bucket] = (int) ($stored[$bucket] ?? 0);
        }

        return $result;
    }

    public static function isTouched(int $teamId): bool
    {
        return EventTeamPhotoCount::query()->where('team', $teamId)->exists();
    }

    /**
     * @param  array<string, int>  $counts keyed by bucket
     */
    public static function replaceForTeam(int $teamId, array $counts): void
    {
        $now = now();

        foreach (self::BUCKETS as $bucket) {
            if (! array_key_exists($bucket, $counts)) {
                throw new \InvalidArgumentException('Missing photo count for '.$bucket);
            }
            $count = (int) $counts[$bucket];
            if ($count < 0) {
                throw new \InvalidArgumentException('Invalid photo count for '.$bucket);
            }

            EventTeamPhotoCount::query()->updateOrCreate(
                ['team' => $teamId, 'bucket' => $bucket],
                ['count' => $count, 'updated_at' => $now],
            );
        }
    }

    /**
     * @param  mixed  $input
     * @return array{ok: true, api: array<string, int>}|array{ok: false, error: string}
     */
    public static function validateCountMap(mixed $input): array
    {
        if (! is_array($input)) {
            return ['ok' => false, 'error' => 'Ungültige Fotoerlaubnis.'];
        }

        $normalized = [];
        foreach (self::BUCKETS as $bucket) {
            if (! array_key_exists($bucket, $input)) {
                return ['ok' => false, 'error' => 'Alle Anzahlen (? / Ja / Nein) sind erforderlich.'];
            }
            $count = self::parseNonNegativeInt($input[$bucket]);
            if ($count === null) {
                return ['ok' => false, 'error' => 'Ungültige Anzahl für '.$bucket.'.'];
            }
            $normalized[$bucket] = $count;
        }

        return ['ok' => true, 'api' => $normalized];
    }

    private static function parseNonNegativeInt(mixed $input): ?int
    {
        if (is_string($input)) {
            $input = trim($input);
            if ($input === '') {
                return null;
            }
        }
        if (! is_numeric($input)) {
            return null;
        }
        $intVal = (int) $input;
        if ($intVal < 0) {
            return null;
        }

        return $intVal;
    }
}
