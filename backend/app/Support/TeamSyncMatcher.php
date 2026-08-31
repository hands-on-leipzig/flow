<?php

namespace App\Support;

/**
 * FLOW ↔ DRAHT team diff (mirrors frontend TeamList mergedTeams logic).
 */
final class TeamSyncMatcher
{
    public static function normalizeTeamNumber(mixed $num): ?int
    {
        if ($num === null || $num === '' || $num === 0) {
            return null;
        }
        $normalized = (int) $num;

        return $normalized === 0 ? null : $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $raw  DRAHT teams map or list
     * @return list<array{number: ?int, name: string, location: ?string, organization: ?string, id: mixed}>
     */
    public static function normalizeDrahtTeams(array $raw): array
    {
        $items = array_is_list($raw) ? $raw : array_values($raw);
        $out = [];

        foreach ($items as $team) {
            if (! is_array($team)) {
                continue;
            }
            $number = self::normalizeTeamNumber($team['ref'] ?? $team['number'] ?? null);
            if ($number === null) {
                continue;
            }
            $out[] = [
                'number' => $number,
                'name' => (string) ($team['name'] ?? ''),
                'location' => $team['location'] ?? null,
                'organization' => $team['organization'] ?? null,
                'id' => $team['id'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $localTeams
     * @param  list<array{number: ?int, name: string, location: ?string, organization: ?string, id: mixed}>  $drahtTeams
     * @return list<array{number: ?int, local: ?array, draht: ?array, status: string}>
     */
    public static function merge(array $localTeams, array $drahtTeams): array
    {
        $result = [];
        $processedLocalIds = [];
        $processedDrahtIds = [];

        $localMapByNumber = [];
        foreach ($localTeams as $t) {
            $num = self::normalizeTeamNumber($t['team_number_hot'] ?? null);
            if ($num !== null) {
                $localMapByNumber[$num] ??= [];
                $localMapByNumber[$num][] = $t;
            }
        }

        $drahtMapByNumber = [];
        foreach ($drahtTeams as $t) {
            $num = $t['number'] ?? null;
            if ($num !== null) {
                $drahtMapByNumber[$num] ??= [];
                $drahtMapByNumber[$num][] = $t;
            }
        }

        $allNumbers = array_unique(array_merge(
            array_keys($localMapByNumber),
            array_keys($drahtMapByNumber)
        ));

        foreach ($allNumbers as $number) {
            $locals = $localMapByNumber[$number] ?? [];
            $drahts = $drahtMapByNumber[$number] ?? [];
            $maxLen = max(count($locals), count($drahts));

            for ($i = 0; $i < $maxLen; $i++) {
                $local = $locals[$i] ?? null;
                $draht = $drahts[$i] ?? null;

                $status = 'match';
                if ($local && $draht) {
                    $status = ($local['name'] ?? '') !== ($draht['name'] ?? '') ? 'conflict' : 'match';
                } elseif ($draht && ! $local) {
                    $status = 'new';
                } elseif ($local && ! $draht) {
                    $status = 'missing';
                }

                if ($local && isset($local['id'])) {
                    $processedLocalIds[(int) $local['id']] = true;
                }
                if ($draht && isset($draht['id'])) {
                    $processedDrahtIds[$draht['id']] = true;
                }

                $result[] = [
                    'number' => $number,
                    'local' => $local,
                    'draht' => $draht,
                    'status' => $status,
                ];
            }
        }

        $localWithoutNumber = array_filter($localTeams, function ($t) use ($processedLocalIds) {
            $num = self::normalizeTeamNumber($t['team_number_hot'] ?? null);
            $id = (int) ($t['id'] ?? 0);

            return $num === null && $id > 0 && ! isset($processedLocalIds[$id]);
        });

        $drahtWithoutNumber = array_filter($drahtTeams, function ($t) use ($processedDrahtIds) {
            return ! isset($processedDrahtIds[$t['id'] ?? '']);
        });

        foreach ($drahtWithoutNumber as $draht) {
            if (($draht['number'] ?? null) !== null) {
                continue;
            }
            $matchingLocal = null;
            foreach ($localWithoutNumber as $local) {
                $lid = (int) ($local['id'] ?? 0);
                if ($lid > 0 && ! isset($processedLocalIds[$lid]) && ($local['name'] ?? '') === ($draht['name'] ?? '')) {
                    $matchingLocal = $local;
                    break;
                }
            }

            if ($matchingLocal) {
                $processedLocalIds[(int) $matchingLocal['id']] = true;
                $result[] = [
                    'number' => null,
                    'local' => $matchingLocal,
                    'draht' => $draht,
                    'status' => ($matchingLocal['name'] ?? '') !== ($draht['name'] ?? '') ? 'conflict' : 'match',
                ];
            } else {
                $result[] = [
                    'number' => null,
                    'local' => null,
                    'draht' => $draht,
                    'status' => 'new',
                ];
            }
        }

        foreach ($localWithoutNumber as $local) {
            $lid = (int) ($local['id'] ?? 0);
            if ($lid > 0 && ! isset($processedLocalIds[$lid])) {
                $result[] = [
                    'number' => null,
                    'local' => $local,
                    'draht' => null,
                    'status' => 'missing',
                ];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{status: string}>  $merged
     * @return array{removed: int, added: int, updated: int}
     */
    public static function actionCounts(array $merged): array
    {
        $removed = $added = $updated = 0;
        foreach ($merged as $row) {
            match ($row['status'] ?? '') {
                'missing' => $removed++,
                'new' => $added++,
                'conflict' => $updated++,
                default => null,
            };
        }

        return compact('removed', 'added', 'updated');
    }
}
