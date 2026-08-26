<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use App\Support\ProgramCatalog;
use App\Support\ProgramPresence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Überblick-style teams preview: 5-minute grid, one column per team, cell = G/J/C/F location.
 */
class TeamsPreviewGridService
{
    private const SLOT_MINUTES = 5;

    public function __construct(
        private ActivityFetcherService $activities,
    ) {}

    /**
     * @return array{
     *   programs: list<array{id: int, label: string, logo: string, style_column: string, columns: list<array{key: string, title: string, style_column: string, program_id: int, index: int}>}>,
     *   eventsByDay: array<string, array{date: Carbon, timeSlots: list<Carbon>, events: list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>}>,
     *   empty: bool
     * }
     */
    public function build(int $planId): array
    {
        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);
        $programIds = $this->programsOnPlan($presence);

        $rolesByProgram = $this->loadTeamRoles($programIds);
        $programs = $this->buildProgramColumns($programIds, $rolesByProgram, $params);

        $byProgramTeam = [];
        foreach ($programs as $program) {
            foreach ($program['columns'] as $col) {
                $byProgramTeam[$program['id'].':'.$col['index']] = $col['key'];
            }
        }

        if ($byProgramTeam === []) {
            return [
                'programs' => $programs,
                'eventsByDay' => [],
                'empty' => true,
            ];
        }

        $raw = $this->activities->fetchActivities(
            plan: $planId,
            roles: [],
            includeActivityMeta: true,
            freeBlocks: false,
        );

        $activities = $raw->filter(function ($a) {
            return (int) ($a->team ?? 0) > 0
                || (int) ($a->table_1_team ?? 0) > 0
                || (int) ($a->table_2_team ?? 0) > 0;
        });

        $placed = $this->placeActivities($activities, $byProgramTeam, $programIds);
        $eventsByDay = $this->bucketByDay($placed);

        return [
            'programs' => $programs,
            'eventsByDay' => $eventsByDay,
            'empty' => $placed === [] && $activities->isEmpty(),
        ];
    }

    /**
     * @return list<int>
     */
    private function programsOnPlan(ProgramPresence $presence): array
    {
        $order = [
            FirstProgram::EXPLORE->value,
            FirstProgram::CHALLENGE->value,
            FirstProgram::FUTURE_8->value,
        ];

        $ids = [];
        foreach ($order as $id) {
            if ($presence->programOn($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $programIds
     * @return array<int, object|null>
     */
    private function loadTeamRoles(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $roles = DB::table('m_role')
            ->where('preview_matrix', 1)
            ->where('differentiation_parameter', 'team')
            ->whereIn('first_program', $programIds)
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $byProgram = [];
        foreach ($programIds as $id) {
            $byProgram[$id] = $roles->firstWhere('first_program', $id);
        }

        return $byProgram;
    }

    /**
     * @param  list<int>  $programIds
     * @param  array<int, object|null>  $rolesByProgram
     * @return list<array{id: int, label: string, logo: string, style_column: string, columns: list<array{key: string, title: string, style_column: string, program_id: int, index: int}>}>
     */
    private function buildProgramColumns(array $programIds, array $rolesByProgram, PlanParameter $params): array
    {
        $programs = [];

        foreach ($programIds as $programId) {
            $role = $rolesByProgram[$programId] ?? null;
            if ($role === null) {
                continue;
            }

            $count = $this->teamCount($programId, $params);
            if ($count < 1) {
                continue;
            }

            $styleColumn = $this->styleColumnForProgram($programId);
            $base = (string) ($role->name_short ?: $role->name);
            $columns = [];
            for ($i = 1; $i <= $count; $i++) {
                $columns[] = [
                    'key' => $this->columnKey((int) $role->id, $i),
                    'title' => $base.$i,
                    'style_column' => $styleColumn,
                    'program_id' => $programId,
                    'index' => $i,
                ];
            }

            $programs[] = [
                'id' => $programId,
                'label' => $this->programLabel($programId),
                'logo' => $this->programLogo($programId),
                'style_column' => $styleColumn,
                'columns' => $columns,
            ];
        }

        return $programs;
    }

    private function teamCount(int $programId, PlanParameter $params): int
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => max(0, (int) $params->get('e1_teams', 0))
                + max(0, (int) $params->get('e2_teams', 0)),
            FirstProgram::CHALLENGE->value => max(0, (int) $params->get('c_teams', 0)),
            FirstProgram::FUTURE_8->value => max(0, (int) $params->get('f8_teams', 0)),
            default => 0,
        };
    }

    private function styleColumnForProgram(int $programId): string
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => 'Explore',
            FirstProgram::CHALLENGE->value => 'Challenge',
            FirstProgram::FUTURE_8->value => 'Future 8+',
            default => 'Allgemein',
        };
    }

    private function programLabel(int $programId): string
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => 'FIRST LEGO League Explore',
            FirstProgram::CHALLENGE->value => 'FIRST LEGO League Challenge',
            FirstProgram::FUTURE_8->value => 'FIRST LEGO League Future 8+',
            default => 'Programm',
        };
    }

    private function programLogo(int $programId): string
    {
        $catalogKey = match ($programId) {
            FirstProgram::EXPLORE->value => ProgramCatalog::EXPLORE,
            FirstProgram::CHALLENGE->value => ProgramCatalog::CHALLENGE,
            FirstProgram::FUTURE_8->value => 'FUTURE_8',
            default => ProgramCatalog::FALLBACK_LOGO_STEM,
        };

        return asset('flow/'.basename(ProgramCatalog::logoPath($catalogKey, 'v')));
    }

    private function columnKey(int $roleId, int $index): string
    {
        return 't'.$roleId.'_'.$index;
    }

    /**
     * @param  Collection<int, object>  $activities
     * @param  array<string, string>  $byProgramTeam  "programId:teamNo" => column key
     * @param  list<int>  $programIds
     * @return list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>
     */
    private function placeActivities(Collection $activities, array $byProgramTeam, array $programIds): array
    {
        $on = array_fill_keys($programIds, true);
        $placed = [];

        foreach ($activities as $a) {
            $programId = (int) ($a->activity_first_program_id ?? 0);
            if ($programId < 1 || ! isset($on[$programId])) {
                continue;
            }

            $code = (string) ($a->activity_type_code ?? '');
            $start = Carbon::parse($a->start_time);
            $end = Carbon::parse($a->end_time);
            $duration = max(self::SLOT_MINUTES, (int) $start->diffInMinutes($end));
            $rowspan = max(1, (int) ceil($duration / self::SLOT_MINUTES));
            $gridStart = $start->copy()->minute((int) (floor($start->minute / self::SLOT_MINUTES) * self::SLOT_MINUTES))->second(0);

            $programStyle = $this->styleColumnForProgram($programId);

            // Jury / Gutachter: team + lane → Gx / Jx
            $team = (int) ($a->team ?? 0);
            $lane = (int) ($a->lane ?? 0);
            if ($team > 0 && $lane > 0) {
                $key = $byProgramTeam[$programId.':'.$team] ?? null;
                if ($key !== null) {
                    $letter = $programId === FirstProgram::EXPLORE->value ? 'G' : 'J';
                    $placed[] = [
                        'column_key' => $key,
                        'start' => $gridStart->copy(),
                        'end' => $end->copy(),
                        'text' => $letter.$lane,
                        'rowspan' => $rowspan,
                        'style_column' => $programStyle,
                    ];
                }
            }

            // Tables: team on table_i → Cx (check) or Fx (game)
            foreach ([1, 2] as $ti) {
                $tableNo = (int) ($a->{'table_'.$ti} ?? 0);
                $tableTeam = (int) ($a->{'table_'.$ti.'_team'} ?? 0);
                if ($tableNo < 1 || $tableTeam < 1) {
                    continue;
                }

                $key = $byProgramTeam[$programId.':'.$tableTeam] ?? null;
                if ($key === null) {
                    continue;
                }

                $isCheck = $code === 'r_check';
                $letter = $isCheck ? 'C' : 'F';
                $style = $isCheck
                    ? 'Robot-Game'
                    : ($programId === FirstProgram::FUTURE_8->value ? 'Game' : 'Robot-Game');

                $placed[] = [
                    'column_key' => $key,
                    'start' => $gridStart->copy(),
                    'end' => $end->copy(),
                    'text' => $letter.$tableNo,
                    'rowspan' => $rowspan,
                    'style_column' => $style,
                ];
            }
        }

        return $placed;
    }

    /**
     * @param  list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>  $placed
     * @return array<string, array{date: Carbon, timeSlots: list<Carbon>, events: list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>}>
     */
    private function bucketByDay(array $placed): array
    {
        $byDay = [];

        foreach ($placed as $event) {
            $dayKey = $event['start']->format('Y-m-d');
            if (! isset($byDay[$dayKey])) {
                $byDay[$dayKey] = [
                    'date' => $event['start']->copy()->startOfDay(),
                    'events' => [],
                ];
            }
            $byDay[$dayKey]['events'][] = $event;
        }

        ksort($byDay);

        foreach ($byDay as $dayKey => &$day) {
            $events = collect($day['events']);
            $earliest = $events->min('start');
            $latest = $events->max('end');

            $startHour = $earliest->hour;
            $endHour = $latest->hour;
            $dayStart = Carbon::createFromTime($startHour, 0, 0);
            $dayEnd = Carbon::createFromTime($endHour, 55, 0);

            $slots = [];
            $current = $dayStart->copy();
            while ($current->lte($dayEnd)) {
                $slots[] = $current->copy();
                $current->addMinutes(self::SLOT_MINUTES);
            }
            $day['timeSlots'] = $slots;
        }
        unset($day);

        return $byDay;
    }
}
