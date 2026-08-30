<?php

namespace App\Support;

use App\Models\Event;
use App\Services\StaffingSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Sanitized open-staffing role names for the public event page.
 * Always includes cross, local, and every attached program (empty roles when none open).
 */
final class PublicHelperSearchPayload
{
    /**
     * @return array{scopes: list<array{key: string, label: string, roles: list<string>, program_id: int|null, color_hex: string|null}>}
     */
    public static function forEvent(Event $event): array
    {
        $event->loadMissing('programs');
        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $catalog = self::programCatalog($programIds);
        $openByKey = [];
        foreach (app(StaffingSyncService::class)->openPositionsByScope((int) $event->id, $programIds) as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $openByKey[$key] = self::unionRoleLabels(
                $row['critical'] ?? [],
                $row['recommended'] ?? [],
            );
        }

        $scopes = [];
        $scopes[] = self::scope(
            'cross',
            'Übergreifend',
            $openByKey['cross'] ?? [],
            null,
            null,
        );

        foreach ($catalog as $programId => $meta) {
            $key = 'program:'.$programId;
            $scopes[] = self::scope(
                $key,
                $meta['label'],
                $openByKey[$key] ?? [],
                $programId,
                $meta['color_hex'],
            );
        }

        $scopes[] = self::scope(
            'local',
            'Zusätzlich',
            $openByKey['local'] ?? [],
            null,
            null,
        );

        return ['scopes' => $scopes];
    }

    /**
     * @param  list<string>  $roles
     * @return array{key: string, label: string, roles: list<string>, program_id: int|null, color_hex: string|null}
     */
    private static function scope(
        string $key,
        string $label,
        array $roles,
        ?int $programId,
        ?string $colorHex,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'roles' => $roles,
            'program_id' => $programId,
            'color_hex' => $colorHex,
        ];
    }

    /**
     * Catalog order (sequence, id).
     *
     * @param  list<int>  $programIds
     * @return array<int, array{label: string, color_hex: string|null}>
     */
    private static function programCatalog(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $rows = DB::table('m_first_program')
            ->whereIn('id', $programIds)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'color_hex']);

        $catalog = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $catalog[$id] = [
                'label' => (string) ($row->display_name ?: $row->name),
                'color_hex' => $row->color_hex !== null
                    ? (string) $row->color_hex
                    : ProgramCatalog::colorHex((string) $row->name),
            ];
        }

        return $catalog;
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
