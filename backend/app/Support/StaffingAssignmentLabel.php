<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class StaffingAssignmentLabel
{
    public static function containerTitle(?string $groupLabel, ?int $groupIndex, string $roleLabel): string
    {
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

    /**
     * @return array<int, list<array{
     *     tile_name: string,
     *     label: string,
     *     role_id: int,
     *     first_program: ?int,
     *     is_local: bool,
     *     sequence: int,
     *     group_index: ?int
     * }>>
     */
    public static function assignmentsByPerson(int $eventId): array
    {
        $programNames = DB::table('m_first_program')->pluck('name', 'id');

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
            $container = self::containerTitle(
                $row->group_label !== null ? (string) $row->group_label : null,
                $groupIndex,
                $roleLabel,
            );
            $programName = $firstProgram ? (string) ($programNames[$firstProgram] ?? '') : null;
            $assignment = [
                'tile_name' => self::tileName($container, $firstProgram, $programName),
                'label' => $roleLabel,
                'role_id' => (int) $row->role_id,
                'first_program' => $firstProgram,
                'is_local' => $isLocal,
                'sequence' => (int) $row->sequence,
                'group_index' => $groupIndex,
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
}
