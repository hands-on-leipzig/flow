<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Support\TableFieldLabels;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublicPlanService
{
    public function __construct(
        private ActivityFetcherService $activities,
        private RoleFetcherService $roleFetcher,
    ) {}

    /**
     * Role picker data (port of zeitplan.cgi get_auswahl).
     */
    public function getRoles(int $planId): array
    {
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select(
                'plan.id as plan_id',
                'plan.event as event_id',
                'event.level as event_level',
                'event.name as event_name',
                'event.slug as event_slug',
                'event.check_in_enabled',
                'event.cockpit_enabled',
            )
            ->first();

        if (! $plan) {
            abort(404, 'Plan not found');
        }

        $teams = $this->teamsByPlanNumber($planId);
        $tableNames = $this->tableNamesForEvent((int) $plan->event_id);

        $roles = [];
        foreach ($this->roleFetcher->fetchRoles($planId) as $role) {
            $roles[] = [
                'id' => (int) $role->id,
                'name' => $role->name,
                'name_short' => $role->name_short,
                'first_program' => $role->first_program !== null ? (int) $role->first_program : null,
                'first_program_name' => $role->first_program_name,
                'color_hex' => $role->color_hex ?: '888888',
                'logo_stem' => $role->logo_stem,
                'logo_white' => $role->logo_white ?: 'FLL_column_heading.png',
                'differentiation_parameter' => $role->differentiation_parameter,
                'options' => $this->roleOptions($role, $planId, $teams, $tableNames),
            ];
        }

        return [
            'plan_id' => $planId,
            'event_id' => (int) $plan->event_id,
            'event_name' => $plan->event_name,
            'slug' => $plan->event_slug ?: null,
            'check_in_enabled' => (bool) $plan->check_in_enabled,
            'cockpit_enabled' => (bool) $plan->cockpit_enabled,
            'roles' => $roles,
        ];
    }

    /**
     * Role-filtered schedule (port of zeitplan.cgi get_detailplan filters).
     */
    public function getSchedule(int $planId, array $query): array
    {
        $plan = DB::table('plan')->where('id', $planId)->first();
        if (! $plan) {
            abort(404, 'Plan not found');
        }

        $role = (int) ($query['role'] ?? 14);
        if ($role < 1) {
            $role = 14;
        }

        $team = isset($query['team']) && $query['team'] !== '' ? (int) $query['team'] : null;
        $lane = isset($query['lane']) && $query['lane'] !== '' ? (int) $query['lane'] : null;
        $table = isset($query['table']) && $query['table'] !== '' ? (int) $query['table'] : null;
        $includeExpired = ! isset($query['expired']) || $query['expired'] === 'yes' || $query['expired'] === '1' || $query['expired'] === true;

        $now = $this->resolveNow($query['now'] ?? null);
        $params = $this->planParameters($planId);

        $rows = $this->activities->fetchActivities(
            $planId,
            [$role],
            includeRooms: true,
            includeGroupMeta: true,
            includeActivityMeta: true,
            includeTeamNames: true,
            freeBlocks: true,
            include_past: false,
        );

        $exploreGroups = DB::table('activity')
            ->whereIn('id', $rows->pluck('activity_id')->filter()->all())
            ->pluck('explore_group', 'id');

        $rows = $rows->filter(function ($row) use ($team, $lane, $table, $role, $params, $exploreGroups, $includeExpired, $now) {
            if ($lane !== null) {
                if ($row->lane !== null && (int) $row->lane !== $lane) {
                    return false;
                }
            }

            if ($table !== null) {
                $t1 = $row->table_1 !== null ? (int) $row->table_1 : null;
                $t2 = $row->table_2 !== null ? (int) $row->table_2 : null;
                if ($t1 !== null || $t2 !== null) {
                    if ($t1 !== $table && $t2 !== $table) {
                        return false;
                    }
                }
            }

            if ($team !== null && ! $this->activityMatchesTeam($row, $team)) {
                return false;
            }

            if (! $this->matchesExploreHalfDay($row, $role, $team, $lane, $params, $exploreGroups)) {
                return false;
            }

            if (! $includeExpired) {
                $end = Carbon::parse($row->end_time, 'Europe/Berlin');
                if ($end->lt($now)) {
                    return false;
                }
            }

            return true;
        })->values();

        return [
            'plan_id' => $planId,
            'role' => $role,
            'team' => $team,
            'lane' => $lane,
            'table' => $table,
            'now' => $now->format('Y-m-d H:i'),
            'expired' => $includeExpired ? 'yes' : 'no',
            'groups' => $this->groupActivities($rows),
        ];
    }

    private function roleOptions(object $role, int $planId, array $teams, array $tableNames): array
    {
        $type = $role->differentiation_type;
        $parameter = $role->differentiation_parameter;
        $roleId = (int) $role->id;
        $firstProgram = $role->first_program !== null ? (int) $role->first_program : null;

        if ($type === 'number' && $role->differentiation_source) {
            $count = $this->runDifferentiationCount($role->differentiation_source, $planId);
            $options = [];
            for ($i = 1; $i <= $count; $i++) {
                $label = "{$role->name} {$i}";
                $noshow = false;

                if (in_array($roleId, [3, 8], true) && $firstProgram) {
                    $team = $teams[$firstProgram][$i] ?? null;
                    if ($team) {
                        $label = $team['name'];
                        if (! empty($team['location'])) {
                            $label .= ' · '.$team['location'];
                        }
                        $noshow = (bool) $team['noshow'];
                    }
                } elseif (in_array($roleId, [5, 11], true)) {
                    $fp = $firstProgram ?? FirstProgram::CHALLENGE->value;
                    $byProgram = $tableNames[$fp] ?? [];
                    $custom = $byProgram[$i] ?? null;
                    if (TableFieldLabels::supports($fp)) {
                        $label = TableFieldLabels::effective($fp, $i, $custom);
                    } else {
                        $label = TableFieldLabels::defaultLabel(FirstProgram::CHALLENGE->value, $i);
                    }
                }

                $options[] = [
                    'value' => $i,
                    'label' => $label,
                    'parameter' => $parameter ?: 'team',
                    'noshow' => $noshow,
                ];
            }

            // If j_lanes / team count is unset, still allow opening the role view
            if ($options === []) {
                return [[
                    'value' => null,
                    'label' => $role->name.' (keine Unterteilung konfiguriert)',
                    'parameter' => null,
                    'noshow' => false,
                ]];
            }

            return $options;
        }

        if ($type === 'list' && $role->differentiation_source) {
            $sql = str_replace('[plan]', (string) $planId, $role->differentiation_source);
            $rows = DB::select($sql);
            $options = [];
            foreach ($rows as $row) {
                $values = array_values((array) $row);
                $options[] = [
                    'value' => $values[0] ?? null,
                    'label' => (string) ($values[1] ?? $values[0] ?? ''),
                    'parameter' => $parameter ?: 'team',
                    'noshow' => false,
                ];
            }

            return $options;
        }

        // No differentiation — single entry for the role itself
        return [[
            'value' => null,
            'label' => $role->name,
            'parameter' => null,
            'noshow' => false,
        ]];
    }

    private function runDifferentiationCount(string $source, int $planId): int
    {
        $sql = str_replace('[plan]', (string) $planId, $source);
        $row = DB::selectOne($sql);
        if (! $row) {
            return 0;
        }
        $values = array_values((array) $row);

        return (int) ($values[0] ?? 0);
    }

    private function planParameters(int $planId): array
    {
        return DB::table('plan_param_value')
            ->join('m_parameter', 'm_parameter.id', '=', 'plan_param_value.parameter')
            ->where('plan_param_value.plan', $planId)
            ->pluck('plan_param_value.set_value', 'm_parameter.name')
            ->all();
    }

    /**
     * @return array<int, array<int, array{name:string,location:?string,noshow:bool}>>
     */
    private function teamsByPlanNumber(int $planId): array
    {
        $rows = DB::table('team_plan')
            ->join('team', 'team.id', '=', 'team_plan.team')
            ->where('team_plan.plan', $planId)
            ->select([
                'team_plan.team_number_plan',
                'team.first_program',
                'team.name',
                'team.location',
                'team_plan.noshow',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $fp = (int) $row->first_program;
            $num = (int) $row->team_number_plan;
            $map[$fp][$num] = [
                'name' => $row->name,
                'location' => $row->location,
                'noshow' => (bool) $row->noshow,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<int, string>> first_program => [table_number => table_name]
     */
    private function tableNamesForEvent(int $eventId): array
    {
        $map = [];
        foreach (
            DB::table('table_event')
                ->where('event', $eventId)
                ->get(['first_program', 'table_number', 'table_name']) as $row
        ) {
            $fp = (int) $row->first_program;
            $num = (int) $row->table_number;
            $name = trim((string) ($row->table_name ?? ''));
            if ($name === '') {
                continue;
            }
            $map[$fp][$num] = $name;
        }

        return $map;
    }

    private function activityMatchesTeam(object $row, int $team): bool
    {
        $atd = (int) ($row->activity_type_detail_id ?? 0);
        $groupAtd = (int) ($row->activity_type_detail ?? $row->activity_type_group ?? 0);

        // 1 Explore judging, 17 Challenge jury, 42 LC with team
        if (in_array($atd, [1, 17, 42], true)) {
            return (int) ($row->team ?? 0) === $team;
        }

        // 15 Match
        if ($atd === 15) {
            $t1 = $row->table_1_team !== null ? (int) $row->table_1_team : null;
            $t2 = $row->table_2_team !== null ? (int) $row->table_2_team : null;
            if ($t1 === $team || $t2 === $team) {
                return true;
            }
            // Shared match-group rows without both teams set (non robot-game rounds)
            $robotGameGroups = [8, 9, 10, 11];
            if (! in_array($groupAtd, $robotGameGroups, true) && ($t1 === null || $t2 === null)) {
                return true;
            }

            return false;
        }

        // 16 Robot-Check
        if ($atd === 16) {
            return (int) ($row->table_1_team ?? 0) === $team
                || (int) ($row->table_2_team ?? 0) === $team;
        }

        // 64/65 Slot blocks
        if (in_array($atd, [64, 65], true)) {
            return (int) ($row->slot_team ?? 0) === $team;
        }

        // Other activities: keep when not team-specific
        return $row->team === null;
    }

    private function matchesExploreHalfDay(
        object $row,
        int $role,
        ?int $team,
        ?int $lane,
        array $params,
        Collection $exploreGroups,
    ): bool {
        if (! in_array($role, [8, 9], true)) {
            return true;
        }

        $eMode = (int) ($params['e_mode'] ?? 0);
        if (! in_array($eMode, [5, 8], true)) {
            return true;
        }

        $exploreGroup = $exploreGroups->get((int) $row->activity_id);
        if ($exploreGroup === null) {
            return true;
        }

        $expected = 1;
        if ($role === 8) {
            $e1Teams = (int) ($params['e1_teams'] ?? 0);
            if ($team !== null && $team > $e1Teams) {
                $expected = 2;
            }
        } else {
            $e1Lanes = (int) ($params['e1_lanes'] ?? 0);
            if ($lane !== null && $lane > $e1Lanes) {
                $expected = 2;
            }
        }

        return (int) $exploreGroup === $expected;
    }

    private function resolveNow(?string $nowParam): Carbon
    {
        if ($nowParam && preg_match('/^(\d{2}|\d{4})-(\d{1,2})-(\d{1,2})[ T+](\d{1,2}):(\d{1,2})$/', urldecode(str_replace('+', ' ', $nowParam)), $m)) {
            $year = strlen($m[1]) === 2 ? '20'.$m[1] : $m[1];

            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%s-%02d-%02d %02d:%02d', $year, $m[2], $m[3], $m[4], $m[5]),
                'Europe/Berlin'
            );
        }

        return now('Europe/Berlin');
    }

    private function groupActivities(Collection $rows): array
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
                        'description' => $row->group_description ?? null,
                        'activity_type_code' => $row->group_activity_type_code ?? null,
                        // punctual | window | info — from m_activity_type_detail.presence
                        'presence' => $row->group_presence ?? 'punctual',
                    ],
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'activities' => [],
                ];
            }

            // Expand group time span
            if ($row->start_time && ($groups[$gid]['start_time'] === null || $row->start_time < $groups[$gid]['start_time'])) {
                $groups[$gid]['start_time'] = $row->start_time;
            }
            if ($row->end_time && ($groups[$gid]['end_time'] === null || $row->end_time > $groups[$gid]['end_time'])) {
                $groups[$gid]['end_time'] = $row->end_time;
            }

            $aid = $row->activity_id;
            if (! isset($groups[$gid]['activities'][$aid])) {
                $groups[$gid]['activities'][$aid] = [
                    'activity_id' => $row->activity_id,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'activity_name' => $row->activity_name,
                    'activity_type_detail_id' => $row->activity_type_detail_id ?? null,
                    'activity_type_code' => $row->activity_type_code ?? null,
                    'presence' => $row->activity_presence ?? 'punctual',
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
                    'table_1_team_name' => $row->table_1_team_name ?? null,
                    'table_2_team_name' => $row->table_2_team_name ?? null,
                    'room' => [
                        'room_type_id' => $row->room_type_id ?? null,
                        'room_type_name' => $row->room_type_name ?? null,
                        'room_id' => $row->room_id ?? null,
                        'room_name' => $row->room_name ?? null,
                    ],
                ];
            }
        }

        foreach ($groups as &$group) {
            $group['activities'] = array_values($group['activities']);
        }

        return array_values($groups);
    }
}
