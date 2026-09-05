<?php

namespace App\Services;

use App\Support\EventDayClock;
use App\Support\PlanParameter;
use App\Support\RoleDifferentiation;
use App\Support\RoleScheduleSlice;
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
        $params = PlanParameter::load($planId);

        $roles = [];
        foreach ($this->roleFetcher->fetchRoles($planId) as $role) {
            if ((int) ($role->public_plan ?? 0) !== 1) {
                continue;
            }

            $firstProgram = $role->first_program !== null ? (int) $role->first_program : null;
            $displayName = trim((string) ($role->first_program_display_name ?? ''));

            $roles[] = [
                'id' => (int) $role->id,
                'name' => $role->name,
                'name_short' => $role->name_short,
                'first_program' => $firstProgram,
                'first_program_name' => $role->first_program_name,
                'first_program_sequence' => $role->first_program_sequence !== null
                    ? (int) $role->first_program_sequence
                    : null,
                'first_program_display_name' => $firstProgram === null
                    ? null
                    : ($displayName !== '' ? $displayName : ($role->first_program_name ?: null)),
                'color_hex' => $role->color_hex ?: '888888',
                'logo_stem' => $role->logo_stem,
                'logo_white' => $role->logo_white ?: 'FLL_column_heading.png',
                'differentiation_parameter' => $role->differentiation_parameter,
                'options' => $this->roleOptions($role, $teams, $params),
            ];
        }

        return [
            'plan_id' => $planId,
            'event_id' => (int) $plan->event_id,
            'event_name' => $plan->event_name,
            'slug' => $plan->event_slug ?: null,
            'check_in_enabled' => (bool) $plan->check_in_enabled,
            'cockpit_enabled' => (bool) $plan->cockpit_enabled,
            'programs' => $this->eventPrograms((int) $plan->event_id),
            'roles' => $roles,
        ];
    }

    /**
     * Role-filtered schedule (port of zeitplan.cgi get_detailplan filters).
     */
    public function getSchedule(int $planId, array $query): array
    {
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select('plan.id', 'event.date as event_date', 'event.days as event_days')
            ->first();
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

        // Wall clock on the event day (preview/live), matching PublicSchedule’s projection.
        $now = $this->resolveNow(
            $query['now'] ?? null,
            $plan->event_date ? (string) $plan->event_date : null,
            max(1, (int) ($plan->event_days ?? 1)),
        );
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
            if (! RoleScheduleSlice::matches(
                $row,
                $lane,
                $table,
                $team,
                fn (object $activity, int $teamNumber): bool => $this->activityMatchesTeam($activity, $teamNumber),
            )) {
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

    private function roleOptions(object $role, array $teams, PlanParameter $params): array
    {
        $parameter = $role->differentiation_parameter;
        $firstProgram = $role->first_program !== null ? (int) $role->first_program : null;
        $count = RoleDifferentiation::optionCount($firstProgram, $parameter, $params);

        if (in_array($parameter, ['lane', 'table', 'team'], true)) {
            $options = [];
            $groupLabel = trim((string) ($role->group_label ?? ''));
            for ($i = 1; $i <= $count; $i++) {
                $label = "{$role->name} {$i}";
                $noshow = false;

                if ($parameter === 'team' && $firstProgram) {
                    $team = $teams[$firstProgram][$i] ?? null;
                    $name = trim((string) ($team['name'] ?? ''));
                    if ($name !== '') {
                        $hot = $team['team_number_hot'] ?? null;
                        $hotStr = $hot !== null && $hot !== '' ? (string) $hot : '';
                        $label = $hotStr !== '' ? "{$name} ({$hotStr})" : $name;
                        $noshow = (bool) ($team['noshow'] ?? false);
                    } else {
                        $label = 'T'.$i.' (Noch nicht angemeldet)';
                    }
                } elseif (in_array($parameter, ['lane', 'table'], true) && $groupLabel !== '') {
                    $label = $groupLabel.' '.$i;
                }

                $options[] = [
                    'value' => $i,
                    'label' => $label,
                    'parameter' => $parameter ?: 'team',
                    'noshow' => $noshow,
                ];
            }

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

        return [[
            'value' => null,
            'label' => $role->name,
            'parameter' => null,
            'noshow' => false,
        ]];
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
     * @return list<array{id:int,display_name:string,sequence:int,logo_stem:?string,logo_white:?string,color_hex:string}>
     */
    private function eventPrograms(int $eventId): array
    {
        $rows = DB::table('event_program as ep')
            ->join('m_first_program as fp', 'fp.id', '=', 'ep.first_program')
            ->where('ep.event', $eventId)
            ->orderBy('fp.sequence')
            ->orderBy('fp.id')
            ->get([
                'fp.id',
                'fp.name',
                'fp.display_name',
                'fp.sequence',
                'fp.logo_stem',
                'fp.logo_white',
                'fp.color_hex',
            ]);

        $programs = [];
        foreach ($rows as $row) {
            $display = trim((string) ($row->display_name ?? ''));
            $programs[] = [
                'id' => (int) $row->id,
                'display_name' => $display !== '' ? $display : (string) $row->name,
                'sequence' => (int) $row->sequence,
                'logo_stem' => $row->logo_stem,
                'logo_white' => $row->logo_white,
                'color_hex' => $row->color_hex ?: '888888',
            ];
        }

        return $programs;
    }

    /**
     * @return array<int, array<int, array{name:string,location:?string,noshow:bool,team_number_hot:int|null}>>
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
                'team.team_number_hot',
                'team_plan.noshow',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $fp = (int) $row->first_program;
            $num = (int) $row->team_number_plan;
            $hot = $row->team_number_hot;
            $map[$fp][$num] = [
                'name' => $row->name,
                'location' => $row->location,
                'noshow' => (bool) $row->noshow,
                'team_number_hot' => $hot !== null && $hot !== '' ? (int) $hot : null,
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

    /**
     * Resolve “now” for expired filtering.
     * Explicit ?now= wins. Otherwise use the shared event-day clock, which is
     * also what the Cockpit timeshift tool compares against.
     */
    private function resolveNow(?string $nowParam, ?string $eventDate = null, int $eventDays = 1): Carbon
    {
        if ($nowParam && preg_match('/^(\d{2}|\d{4})-(\d{1,2})-(\d{1,2})[ T+](\d{1,2}):(\d{1,2})$/', urldecode(str_replace('+', ' ', $nowParam)), $m)) {
            $year = strlen($m[1]) === 2 ? '20'.$m[1] : $m[1];

            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%s-%02d-%02d %02d:%02d', $year, $m[2], $m[3], $m[4], $m[5]),
                EventDayClock::TZ
            );
        }

        return EventDayClock::pivot($eventDate, $eventDays);
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
