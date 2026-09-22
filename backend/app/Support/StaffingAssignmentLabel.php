<?php

namespace App\Support;

use App\Enums\FirstProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StaffingAssignmentLabel
{
    public static function containerTitle(?string $groupLabel, ?int $groupIndex, string $roleLabel, ?string $placeLabel = null): string
    {
        if (self::isAllianz($groupLabel, $roleLabel)) {
            $placeLabel = null;
        }
        if ($placeLabel !== null && $placeLabel !== '') {
            if (self::isRobotCheck($groupLabel)) {
                return 'Robot-Check für '.$placeLabel;
            }

            return $placeLabel;
        }
        if ($groupLabel !== null && $groupLabel !== '' && $groupIndex !== null && $groupIndex > 0) {
            return $groupLabel.' '.$groupIndex;
        }

        return $roleLabel;
    }

    public static function tileName(string $container, ?int $firstProgram, ?string $programName): string
    {
        if ($firstProgram && $programName !== null && $programName !== '') {
            return $programName.': '.$container;
        }

        return $container;
    }

    public static function assignmentCaption(string $roleLabel, ?string $groupLabel, ?int $groupIndex, ?string $placeLabel = null): string
    {
        $container = self::containerTitle($groupLabel, $groupIndex, $roleLabel, $placeLabel);
        if ($container !== $roleLabel) {
            return $roleLabel.' ('.$container.')';
        }

        return $roleLabel;
    }

    /**
     * @param  array{counts: array<int, int>, customs: array<int, array<int, string>>}  $maps
     */
    public static function placeLabelForGroup(
        ?string $groupLabel,
        ?string $roleLabel,
        ?int $firstProgram,
        ?int $groupIndex,
        array $maps,
    ): ?string {
        if (self::isAllianz($groupLabel, $roleLabel) || ! self::usesPlaceHelper($groupLabel)) {
            return null;
        }
        if ($firstProgram === null || $groupIndex === null || $groupIndex < 1) {
            return null;
        }
        if (! TableFieldLabels::supports($firstProgram)) {
            return null;
        }

        $count = $maps['counts'][$firstProgram] ?? 0;
        $custom = $maps['customs'][$firstProgram][$groupIndex] ?? null;
        $label = TableFieldLabels::effective($firstProgram, $groupIndex, $custom, $count);

        return $label !== '' ? $label : null;
    }

    /**
     * @return array{counts: array<int, int>, customs: array<int, array<int, string>>}
     */
    public static function placeMapsForEvent(int $eventId): array
    {
        $counts = [];
        $customs = [];
        $planId = 0;
        if (Schema::hasTable('plan')) {
            $planId = (int) (DB::table('plan')->where('event', $eventId)->value('id') ?? 0);
        }
        if ($planId > 0) {
            foreach ([FirstProgram::CHALLENGE->value, FirstProgram::FUTURE_8->value] as $fp) {
                $counts[$fp] = self::tableCountFromPlan($planId, $fp);
            }
        }

        if ($eventId > 0 && Schema::hasTable('table_event')) {
            foreach (
                DB::table('table_event')
                    ->where('event', $eventId)
                    ->whereIn('first_program', [FirstProgram::CHALLENGE->value, FirstProgram::FUTURE_8->value])
                    ->get(['first_program', 'table_number', 'table_name']) as $row
            ) {
                $name = trim((string) ($row->table_name ?? ''));
                if ($name === '') {
                    continue;
                }
                $customs[(int) $row->first_program][(int) $row->table_number] = $name;
            }
        }

        return [
            'counts' => $counts,
            'customs' => $customs,
        ];
    }

    /**
     * @return array<int, list<array{
     *     tile_name: string,
     *     caption: string,
     *     label: string,
     *     role_id: int,
     *     first_program: ?int,
     *     is_local: bool,
     *     sequence: int,
     *     catalog_sequence: ?int,
     *     group_index: ?int,
     *     group_label: ?string
     * }>>
     */
    public static function assignmentsByPerson(int $eventId): array
    {
        $programNames = DB::table('m_first_program')->pluck('name', 'id');
        $maps = self::placeMapsForEvent($eventId);

        $rows = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_role as r', 'r.id', '=', 'a.event_staffing_role')
            ->leftJoin('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->leftJoin('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->where('r.event', $eventId)
            ->orderBy('r.sequence')
            ->orderBy('r.id')
            ->orderBy('g.group_index')
            ->get([
                'a.volunteer_person',
                'r.id as role_id',
                'r.label as role_label',
                'r.group_label',
                'r.sequence',
                'r.m_role',
                'mr.name as catalog_name',
                'mr.first_program',
                'mr.sequence as catalog_sequence',
                'g.group_index',
            ]);

        $assignmentsByPerson = [];
        foreach ($rows as $row) {
            $personId = (int) $row->volunteer_person;
            $roleLabel = trim((string) ($row->role_label ?: ($row->catalog_name ?: 'Rolle')));
            $isLocal = $row->m_role === null;
            $firstProgram = (! $isLocal && $row->first_program !== null)
                ? (int) $row->first_program
                : null;
            $groupIndex = $row->group_index !== null ? (int) $row->group_index : null;
            $groupLabel = $groupIndex !== null && $row->group_label !== null && $row->group_label !== ''
                ? (string) $row->group_label
                : null;
            $placeLabel = self::placeLabelForGroup($groupLabel, $roleLabel, $firstProgram, $groupIndex, $maps);
            $container = self::containerTitle($groupLabel, $groupIndex, $roleLabel, $placeLabel);
            $programName = $firstProgram ? (string) ($programNames[$firstProgram] ?? '') : null;
            $assignment = [
                'tile_name' => self::tileName($container, $firstProgram, $programName),
                'caption' => self::assignmentCaption($roleLabel, $groupLabel, $groupIndex, $placeLabel),
                'label' => $roleLabel,
                'role_id' => (int) $row->role_id,
                'first_program' => $firstProgram,
                'is_local' => $isLocal,
                'sequence' => (int) $row->sequence,
                'catalog_sequence' => $row->catalog_sequence !== null ? (int) $row->catalog_sequence : null,
                'group_index' => $groupIndex,
                'group_label' => $groupLabel,
            ];

            if (! isset($assignmentsByPerson[$personId])) {
                $assignmentsByPerson[$personId] = [];
            }

            foreach ($assignmentsByPerson[$personId] as $existing) {
                if ($existing['tile_name'] === $assignment['tile_name']) {
                    continue 2;
                }
            }
            $assignmentsByPerson[$personId][] = $assignment;
        }

        return $assignmentsByPerson;
    }

    public static function isAllianz(?string $groupLabel, ?string $roleLabel = null): bool
    {
        $hay = mb_strtolower(trim(($groupLabel ?? '').' '.($roleLabel ?? '')));

        return str_contains($hay, 'allianz');
    }

    private static function isRobotCheck(?string $groupLabel): bool
    {
        return str_contains(mb_strtolower((string) $groupLabel), 'robot-check');
    }

    private static function usesPlaceHelper(?string $groupLabel): bool
    {
        $g = mb_strtolower((string) $groupLabel);
        if ($g === '') {
            return false;
        }

        return str_contains($g, 'robot-check')
            || str_contains($g, 'tisch')
            || str_contains($g, 'feld')
            || str_contains($g, 'matte');
    }

    private static function tableCountFromPlan(int $planId, int $firstProgram): int
    {
        try {
            $name = TableFieldLabels::countParamName($firstProgram);
        } catch (\Throwable) {
            return 0;
        }

        $row = DB::table('m_parameter as mp')
            ->leftJoin('plan_param_value as ppv', function ($j) use ($planId) {
                $j->on('ppv.parameter', '=', 'mp.id')
                    ->where('ppv.plan', '=', $planId);
            })
            ->where('mp.name', $name)
            ->selectRaw('COALESCE(ppv.set_value, mp.value) as value')
            ->first();

        return max(0, (int) ($row->value ?? 0));
    }
}
