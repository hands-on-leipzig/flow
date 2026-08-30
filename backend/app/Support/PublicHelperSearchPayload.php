<?php

namespace App\Support;

use App\Models\Event;
use App\Services\StaffingSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Sanitized open-staffing role names for the public event page.
 */
final class PublicHelperSearchPayload
{
    /**
     * @return array{scopes: list<array{key: string, label: string, roles: list<string>}>}
     */
    public static function forEvent(Event $event): array
    {
        $event->loadMissing('programs');
        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $programLabels = self::programLabels($programIds);
        $open = app(StaffingSyncService::class)->openPositionsByScope((int) $event->id, $programIds);

        $scopes = [];
        foreach ($open as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $roles = self::unionRoleLabels(
                $row['critical'] ?? [],
                $row['recommended'] ?? [],
            );
            if ($roles === []) {
                continue;
            }

            $scopes[] = [
                'key' => $key,
                'label' => self::scopeLabel($key, $programLabels),
                'roles' => $roles,
            ];
        }

        return ['scopes' => $scopes];
    }

    /**
     * @param  list<int>  $programIds
     * @return array<int, string>
     */
    private static function programLabels(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $rows = DB::table('m_first_program')
            ->whereIn('id', $programIds)
            ->get(['id', 'name', 'display_name']);

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row->id] = (string) ($row->display_name ?: $row->name);
        }

        return $labels;
    }

    /**
     * @param  array<int, string>  $programLabels
     */
    private static function scopeLabel(string $key, array $programLabels): string
    {
        if ($key === 'cross') {
            return 'Übergreifend';
        }
        if ($key === 'local') {
            return 'Zusätzlich';
        }
        if (str_starts_with($key, 'program:')) {
            $id = (int) substr($key, strlen('program:'));

            return $programLabels[$id] ?? 'Programm';
        }

        return $key;
    }

    /**
     * @param  list<array{role_id?: int, label?: string, sequence?: int}>  $critical
     * @param  list<array{role_id?: int, label?: string, sequence?: int}>  $recommended
     * @return list<string>
     */
    private static function unionRoleLabels(array $critical, array $recommended): array
    {
        /** @var array<int, array{label: string, sequence: int}> $byRole */
        $byRole = [];
        foreach (array_merge($critical, $recommended) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $roleId = (int) ($entry['role_id'] ?? 0);
            if ($roleId <= 0 || isset($byRole[$roleId])) {
                continue;
            }
            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $byRole[$roleId] = [
                'label' => $label,
                'sequence' => (int) ($entry['sequence'] ?? 0),
            ];
        }

        $rows = array_values($byRole);
        usort(
            $rows,
            fn (array $a, array $b) => ($a['sequence'] <=> $b['sequence']) ?: strcmp($a['label'], $b['label'])
        );

        return array_map(fn (array $row) => $row['label'], $rows);
    }
}
