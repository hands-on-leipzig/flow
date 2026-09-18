<?php

declare(strict_types=1);

namespace App\Print;

use App\Services\ActivityFetcherService;
use App\Services\EventTitleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RoomSheetAssembler
{
    public function __construct(
        private ActivityFetcherService $fetcher,
        private EventTitleService $titles,
    ) {}

    /**
     * @return array{
     *     title_short: string,
     *     title_long: string,
     *     show_program_logos: bool,
     *     programs: list<array{id:int,display_name:string,logo_stem:?string}>,
     *     sections: list<array<string, mixed>>
     * }|null
     */
    public function assemble(int $planId): ?array
    {
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select(
                'plan.id as plan_id',
                'plan.event as event_id',
                'event.name as event_name',
                'event.level as event_level',
            )
            ->first();
        if (! $plan) {
            return null;
        }

        $eventId = (int) $plan->event_id;
        $programs = $this->eventPrograms($eventId);
        $programIds = [];
        foreach ($programs as $program) {
            $programIds[$program['id']] = true;
        }

        $rows = $this->fetcher->fetchActivities(
            $planId,
            [],
            true,
            true,
            true,
            true,
            true,
            false,
        );
        $teams = $this->assignedTeams($planId);
        $teamLookup = $this->teamLookup($teams);
        $activitiesByRoom = $this->activitiesByRoom($rows, $teamLookup);
        $teamsByRoom = $this->teamsByRoom($teams, $programIds);

        $roomIds = array_unique(array_merge(array_keys($activitiesByRoom), array_keys($teamsByRoom)));
        if ($roomIds === []) {
            $titles = $this->titles->titles($this->eventObject($plan, $programs));

            return [
                'title_short' => $titles['title_short'],
                'title_long' => $titles['title_long'],
                'show_program_logos' => count($programs) >= 2,
                'programs' => $programs,
                'sections' => [],
            ];
        }

        $rooms = DB::table('room')
            ->where('event', $eventId)
            ->whereIn('id', $roomIds)
            ->orderByRaw('COALESCE(sequence, 9999)')
            ->orderBy('name')
            ->get(['id', 'name', 'sequence']);

        $sections = [];
        foreach ($rooms as $room) {
            $roomId = (int) $room->id;
            $activities = $activitiesByRoom[$roomId] ?? [];
            $roomTeams = $teamsByRoom[$roomId] ?? [];
            if ($activities === [] && $roomTeams === []) {
                continue;
            }
            $section = [
                'subject' => (string) $room->name,
                'color_hex' => '888888',
                'logo_stem' => null,
                'noshow' => false,
                'activities' => $activities,
            ];
            if ($roomTeams !== []) {
                $section['team_columns'] = $this->teamColumns($programs, $roomTeams);
            }
            $sections[] = $section;
        }

        $titles = $this->titles->titles($this->eventObject($plan, $programs));

        return [
            'title_short' => $titles['title_short'],
            'title_long' => $titles['title_long'],
            'show_program_logos' => count($programs) >= 2,
            'programs' => $programs,
            'sections' => $sections,
        ];
    }

    /**
     * @return list<array{id:int,display_name:string,logo_stem:?string,sequence:int}>
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
            ]);

        $programs = [];
        foreach ($rows as $row) {
            $display = trim((string) ($row->display_name ?? ''));
            $programs[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'display_name' => $display !== '' ? $display : (string) $row->name,
                'sequence' => (int) $row->sequence,
                'logo_stem' => $row->logo_stem !== null && $row->logo_stem !== '' ? (string) $row->logo_stem : null,
            ];
        }

        return $programs;
    }

    /**
     * @return Collection<int, object>
     */
    private function assignedTeams(int $planId): Collection
    {
        return DB::table('team_plan')
            ->join('team', 'team.id', '=', 'team_plan.team')
            ->where('team_plan.plan', $planId)
            ->whereNotNull('team_plan.room')
            ->select([
                'team_plan.room',
                'team_plan.team_number_plan',
                'team_plan.noshow',
                'team.name',
                'team.team_number_hot',
                'team.first_program',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, object>  $teams
     * @return array<int, array<int, object>>
     */
    private function teamLookup(Collection $teams): array
    {
        $map = [];
        foreach ($teams as $team) {
            $program = (int) ($team->first_program ?? 0);
            $number = (int) ($team->team_number_plan ?? 0);
            $map[$program][$number] = $team;
        }

        return $map;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<int, array<int, object>>  $teamLookup
     * @return array<int, list<array{start:string,end:string,program_id:?int,action:string,strike:list<string>}>>
     */
    private function activitiesByRoom(Collection $rows, array $teamLookup): array
    {
        $seen = [];
        $byRoom = [];
        $sortable = [];
        foreach ($rows as $row) {
            $roomId = isset($row->room_id) && $row->room_id !== null && $row->room_id !== ''
                ? (int) $row->room_id
                : 0;
            if ($roomId < 1) {
                continue;
            }
            $activityId = (int) ($row->activity_id ?? 0);
            if ($activityId > 0 && isset($seen[$activityId])) {
                continue;
            }
            if ($activityId > 0) {
                $seen[$activityId] = true;
            }
            $activity = $this->activityArray($row, $teamLookup);
            $action = RoomSheetCells::action($activity);
            $sortable[$roomId][] = [
                'start_sort' => (string) ($row->start_time ?? ''),
                'end_sort' => (string) ($row->end_time ?? ''),
                'row' => [
                    'start' => self::clock($row->start_time ?? null),
                    'end' => self::clock($row->end_time ?? null),
                    'program_id' => self::programId($row),
                    'action' => $action['text'],
                    'strike' => $action['strike'],
                ],
            ];
        }

        foreach ($sortable as $roomId => $items) {
            usort($items, function (array $a, array $b): int {
                $byStart = strcmp($a['start_sort'], $b['start_sort']);
                if ($byStart !== 0) {
                    return $byStart;
                }

                return strcmp($a['end_sort'], $b['end_sort']);
            });
            $byRoom[$roomId] = array_column($items, 'row');
        }

        return $byRoom;
    }

    /**
     * @param  array<int, array<int, object>>  $teamLookup
     * @return array<string, mixed>
     */
    private function activityArray(object $row, array $teamLookup): array
    {
        $activity = (array) $row;
        $code = (string) ($activity['activity_type_code'] ?? '');
        if (! in_array($code, ['e_slot_block', 'c_slot_block', 'f8_slot_block', 'g_slot_block'], true)) {
            return $activity;
        }
        $slot = isset($activity['slot_team']) && $activity['slot_team'] !== null && $activity['slot_team'] !== ''
            ? (int) $activity['slot_team']
            : 0;
        $program = self::programId($row) ?? 0;
        $hit = $teamLookup[$program][$slot] ?? null;
        if ($hit === null && $slot > 0) {
            foreach ($teamLookup as $byNumber) {
                if (isset($byNumber[$slot])) {
                    $hit = $byNumber[$slot];
                    break;
                }
            }
        }
        $activity['slot_team'] = $slot > 0 ? $slot : ($activity['slot_team'] ?? null);
        $activity['slot_team_name'] = $hit->name ?? '';
        $activity['slot_team_number_hot'] = $hit->team_number_hot ?? null;
        $activity['slot_team_noshow'] = (bool) ($hit->noshow ?? false);

        return $activity;
    }

    /**
     * @param  Collection<int, object>  $teams
     * @param  array<int, true>  $programIds
     * @return array<int, list<object>>
     */
    private function teamsByRoom(Collection $teams, array $programIds): array
    {
        $byRoom = [];
        foreach ($teams as $team) {
            $roomId = (int) ($team->room ?? 0);
            $program = (int) ($team->first_program ?? 0);
            if ($roomId < 1 || ! isset($programIds[$program])) {
                continue;
            }
            $byRoom[$roomId][] = $team;
        }

        return $byRoom;
    }

    /**
     * @param  list<array{id:int,display_name:string,logo_stem:?string,sequence:int}>  $programs
     * @param  list<object>  $roomTeams
     * @return list<array{program_id:int,display_name:string,logo_stem:?string,teams:list<array{label:string,noshow:bool}>}>
     */
    private function teamColumns(array $programs, array $roomTeams): array
    {
        $byProgram = [];
        foreach ($roomTeams as $team) {
            $byProgram[(int) $team->first_program][] = $team;
        }
        $columns = [];
        foreach ($programs as $program) {
            $id = $program['id'];
            $list = $byProgram[$id] ?? [];
            usort($list, fn (object $a, object $b): int => ((int) $a->team_number_plan) <=> ((int) $b->team_number_plan));
            $teams = [];
            foreach ($list as $team) {
                $teams[] = [
                    'label' => RoleSheetCells::teamLabel(
                        (string) ($team->name ?? ''),
                        $team->team_number_plan,
                        $team->team_number_hot,
                        true,
                    ),
                    'noshow' => (bool) ($team->noshow ?? false),
                ];
            }
            $columns[] = [
                'program_id' => $id,
                'display_name' => $program['display_name'],
                'logo_stem' => $program['logo_stem'],
                'teams' => $teams,
            ];
        }

        return $columns;
    }

    /**
     * @param  list<array{id:int,display_name:string,logo_stem:?string,sequence:int,name?:string}>  $programs
     */
    private function eventObject(object $plan, array $programs): object
    {
        $named = [];
        foreach ($programs as $program) {
            $named[] = (object) ['name' => $program['name'] ?? $program['display_name']];
        }

        return (object) [
            'name' => $plan->event_name ?? '',
            'level' => $plan->event_level ?? 0,
            'programs' => $named,
        ];
    }

    private static function programId(object $row): ?int
    {
        if (! isset($row->activity_first_program_id) || $row->activity_first_program_id === null || $row->activity_first_program_id === '') {
            return null;
        }
        $id = (int) $row->activity_first_program_id;

        return $id > 0 ? $id : null;
    }

    private static function clock(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}:\d{2})/', $value, $match)) {
            $parts = explode(':', $match[1]);

            return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
        }

        return '';
    }
}
