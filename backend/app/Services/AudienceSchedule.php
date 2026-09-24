<?php

namespace App\Services;

use App\Enums\FirstProgram;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AudienceSchedule
{
    public function __construct(private ActivityFetcherService $activities) {}

    /**
     * @param  list<int>  $roles
     */
    public function rows(int $planId, array $roles, ?int $roomId): Collection
    {
        $roles = array_values(array_unique(array_map('intval', $roles)));
        if ($roles === []) {
            return collect();
        }

        $roomId = $roomId !== null && $roomId > 0 ? $roomId : null;

        return $this->activities->fetchActivities(
            $planId,
            $roles,
            includeRooms: true,
            includeGroupMeta: true,
            includeActivityMeta: true,
            includeTeamNames: true,
            freeBlocks: true,
            include_past: false,
            rooms: $roomId === null ? [] : [$roomId],
        );
    }

    /**
     * @param  list<int>  $programIds
     * @return list<int>
     */
    public function rolesForSelection(bool $joint, array $programIds): array
    {
        $roles = [];
        if ($joint) {
            $roles[] = FirstProgram::JOINT->audienceRoleId();
        }

        foreach ($programIds as $programId) {
            $role = FirstProgram::tryFrom((int) $programId)?->audienceRoleId();
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return array_values(array_unique(array_filter($roles, fn ($role) => $role !== null)));
    }

    /**
     * @param  list<int>  $programIds
     */
    public function keepSelected(Collection $rows, bool $joint, array $programIds): Collection
    {
        $ids = array_map('intval', $programIds);

        return $rows->filter(function ($row) use ($joint, $ids) {
            $programId = $row->group_first_program_id ?? null;
            $isJoint = $programId === null || (int) $programId === 0;
            if ($isJoint) {
                return $joint;
            }

            return in_array((int) $programId, $ids, true);
        })->values();
    }

    public function window(Collection $rows, string $name, Carbon $pivot, int $intervalMinutes): Collection
    {
        return $rows->filter(function ($row) use ($name, $pivot, $intervalMinutes) {
            $start = Carbon::parse($row->start_time, 'Europe/Berlin');
            $end = Carbon::parse($row->end_time, 'Europe/Berlin');

            return match ($name) {
                'full' => true,
                'rest' => ! $end->lt($pivot),
                'now' => $start->lte($pivot) && $end->gte($pivot),
                'next' => $start->gte($pivot) && $start->lte($pivot->copy()->addMinutes($intervalMinutes)),
                default => true,
            };
        })->values();
    }

    public function present(Collection $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $gid = $row->activity_group_id ?? null;
            if (! isset($groups[$gid])) {
                $groups[$gid] = [
                    'activity_group_id' => $gid,
                    'group_meta' => [
                        'name' => $row->group_atd_name ?? null,
                        'first_program_id' => $row->group_first_program_id ?? null,
                        'first_program_name' => $row->group_first_program_name ?? null,
                        'logo_stem' => $row->group_logo_stem ?? null,
                        'display_name' => $row->group_first_program_display_name ?? null,
                        'official_name' => $row->group_first_program_official_name ?? null,
                        'description' => $row->group_description ?? null,
                        'activity_type_code' => $row->group_activity_type_code ?? null,
                        'presence' => $row->group_presence ?? 'punctual',
                    ],
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'activities' => [],
                ];
            }

            if ($row->start_time && ($groups[$gid]['start_time'] === null || $row->start_time < $groups[$gid]['start_time'])) {
                $groups[$gid]['start_time'] = $row->start_time;
            }
            if ($row->end_time && ($groups[$gid]['end_time'] === null || $row->end_time > $groups[$gid]['end_time'])) {
                $groups[$gid]['end_time'] = $row->end_time;
            }

            $aid = $row->activity_id;
            if (! isset($groups[$gid]['activities'][$aid])) {
                $roomNav = trim((string) ($row->room_navigation ?? ''));
                $roomAccessible = $row->room_is_accessible ?? null;
                $groups[$gid]['activities'][$aid] = [
                    'activity_id' => $row->activity_id,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'activity_name' => $row->activity_atd_name ?? $row->activity_name,
                    'activity_type_detail_id' => $row->activity_type_detail_id ?? null,
                    'activity_type_code' => $row->activity_type_code ?? null,
                    'presence' => $row->activity_presence ?? 'punctual',
                    'extra_block_id' => self::extraBlockId($row),
                    'extra_block_type' => self::extraBlockType($row),
                    'meta' => [
                        'name' => $row->activity_atd_name ?? null,
                        'first_program_id' => $row->activity_first_program_id ?? null,
                        'first_program_name' => $row->activity_first_program_name ?? null,
                        'description' => $row->activity_description ?? null,
                    ],
                    'program' => $row->program_name,
                    'lane' => $row->lane,
                    'team' => $row->team,
                    'table_1' => $row->table_1,
                    'table_1_name' => $row->table_1_name ?? null,
                    'table_1_team' => $row->table_1_team,
                    'table_2' => $row->table_2,
                    'table_2_name' => $row->table_2_name ?? null,
                    'table_2_team' => $row->table_2_team,
                    'team_name' => $row->jury_team_name ?? null,
                    'jury_team_number_hot' => $row->jury_team_number_hot ?? null,
                    'jury_team_noshow' => (bool) ($row->jury_team_noshow ?? false),
                    'table_1_team_name' => $row->table_1_team_name ?? null,
                    'table_1_team_number_hot' => $row->table_1_team_number_hot ?? null,
                    'table_1_team_noshow' => (bool) ($row->table_1_team_noshow ?? false),
                    'table_2_team_name' => $row->table_2_team_name ?? null,
                    'table_2_team_number_hot' => $row->table_2_team_number_hot ?? null,
                    'table_2_team_noshow' => (bool) ($row->table_2_team_noshow ?? false),
                    'room' => [
                        'room_type_id' => $row->room_type_id ?? null,
                        'room_type_name' => $row->room_type_name ?? null,
                        'room_id' => $row->room_id ?? null,
                        'room_name' => $row->room_name ?? null,
                        'navigation' => $roomNav !== '' ? $roomNav : null,
                        'accessible' => $roomAccessible === null ? true : (bool) $roomAccessible,
                    ],
                ];
            }
        }

        foreach ($groups as &$group) {
            $group['activities'] = array_values($group['activities']);
        }

        return array_values($groups);
    }

    private static function extraBlockId(object $row): ?int
    {
        $id = $row->extra_block_id ?? $row->is_extra_block ?? null;
        if ($id === null || $id === '' || $id === false) {
            return null;
        }
        $id = (int) $id;

        return $id > 0 ? $id : null;
    }

    private static function extraBlockType(object $row): ?string
    {
        $type = trim((string) ($row->extra_block_type ?? ''));

        return $type !== '' ? $type : null;
    }
}
