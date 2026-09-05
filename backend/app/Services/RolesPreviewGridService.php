<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use App\Support\PreviewGridOverlapResolver;
use App\Support\ProgramCatalog;
use App\Support\ProgramPresence;
use App\Support\RoleDifferentiation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Überblick-style roles preview: 5-minute activity grid, param-driven lane/table columns.
 */
class RolesPreviewGridService
{
    private const SLOT_MINUTES = 5;

    /** Live Challenge Jury — out of scope for this grid. */
    private const LC_ROLE_ID = 16;

    public function __construct(
        private ActivityFetcherService $activities,
        private RoleFetcherService $roleFetcher,
    ) {}

    /**
     * @return array{
     *   programs: list<array{id: int, label: string, logo: string, style_column: string, columns: list<array{key: string, title: string, style_column: string}>}>,
     *   eventsByDay: array<string, array{date: Carbon, timeSlots: list<Carbon>, events: list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>}>,
     *   empty: bool,
     *   has_overlaps: bool
     * }
     */
    public function build(int $planId): array
    {
        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);
        $programIds = $this->programsOnPlan($presence);

        $rolesByProgram = $this->loadRoles($planId, $programIds);
        $programs = $this->buildProgramColumns($programIds, $rolesByProgram, $params);

        $flatColumns = [];
        foreach ($programs as $program) {
            foreach ($program['columns'] as $col) {
                $flatColumns[$col['key']] = $col;
            }
        }

        if ($flatColumns === []) {
            return [
                'programs' => $programs,
                'eventsByDay' => [],
                'empty' => true,
                'has_overlaps' => false,
            ];
        }

        $raw = $this->activities->fetchActivities(
            plan: $planId,
            roles: $this->previewRoleIds($rolesByProgram),
            includeActivityMeta: true,
            freeBlocks: false,
        );

        $activities = $raw->filter(function ($a) {
            return (int) ($a->lane ?? 0) > 0
                || (int) ($a->table_1 ?? 0) > 0
                || (int) ($a->table_2 ?? 0) > 0;
        });

        $columnIndex = $this->indexColumnsByRole($programs, $rolesByProgram);
        $placed = $this->placeActivities($activities, $columnIndex, $rolesByProgram);

        $overlap = PreviewGridOverlapResolver::resolve($placed);
        $placed = $overlap['events'];
        $eventsByDay = $this->bucketByDay($placed);

        return [
            'programs' => $programs,
            'eventsByDay' => $eventsByDay,
            'empty' => $placed === [] && $activities->isEmpty(),
            'has_overlaps' => $overlap['has_overlaps'],
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
     * @return array<int, Collection<int, object>>
     */
    private function loadRoles(int $planId, array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $programIdSet = array_fill_keys($programIds, true);

        $roles = $this->roleFetcher->fetchRoles($planId)
            ->filter(function ($role) use ($programIdSet) {
                if ((int) $role->preview_matrix !== 1) {
                    return false;
                }
                if (! in_array($role->differentiation_parameter, ['lane', 'table'], true)) {
                    return false;
                }
                $fp = $role->first_program !== null ? (int) $role->first_program : null;
                if ($fp === null || ! isset($programIdSet[$fp])) {
                    return false;
                }
                if ((int) $role->id === self::LC_ROLE_ID) {
                    return false;
                }
                $short = strtoupper(trim((string) ($role->name_short ?? '')));

                return ! str_starts_with($short, 'LC');
            });

        return $roles->groupBy(fn ($r) => (int) $r->first_program)->all();
    }

    /**
     * @param  array<int, Collection<int, object>>  $rolesByProgram
     * @return list<int>
     */
    private function previewRoleIds(array $rolesByProgram): array
    {
        $ids = [];
        foreach ($rolesByProgram as $roles) {
            foreach ($roles as $role) {
                $ids[] = (int) $role->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $programIds
     * @param  array<int, Collection<int, object>>  $rolesByProgram
     * @return list<array{id: int, label: string, logo: string, style_column: string, columns: list<array{key: string, title: string, style_column: string}>}>
     */
    private function buildProgramColumns(array $programIds, array $rolesByProgram, PlanParameter $params): array
    {
        $programs = [];

        foreach ($programIds as $programId) {
            $roles = $rolesByProgram[$programId] ?? collect();
            if ($roles->isEmpty()) {
                continue;
            }

            $styleColumn = $this->styleColumnForProgram($programId);
            $label = $this->programLabel($programId);
            $logo = $this->programLogo($programId);
            $columns = [];

            foreach ($roles as $role) {
                if ($this->isRobotCheckRole($role) && ! (int) $params->get('r_robot_check', 0)) {
                    continue;
                }

                $count = RoleDifferentiation::optionCount($programId, (string) $role->differentiation_parameter, $params);
                if ($count < 1) {
                    continue;
                }

                $base = (string) ($role->name_short ?: $role->name);
                for ($i = 1; $i <= $count; $i++) {
                    $columns[] = [
                        'key' => $this->columnKey((int) $role->id, $i),
                        'title' => $base.$i,
                        'style_column' => $styleColumn,
                        'program_id' => $programId,
                        'role_id' => (int) $role->id,
                        'index' => $i,
                    ];
                }
            }

            if ($columns === []) {
                continue;
            }

            $programs[] = [
                'id' => $programId,
                'label' => $label,
                'logo' => $logo,
                'style_column' => $styleColumn,
                'columns' => $columns,
            ];
        }

        return $programs;
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
        return 'r'.$roleId.'_'.$index;
    }

    private function isRobotCheckRole(object $role): bool
    {
        $short = strtoupper(trim((string) ($role->name_short ?? '')));

        return str_starts_with($short, 'RC');
    }

    /**
     * @param  list<array{id: int, columns: list<array{key: string, role_id: int, index: int, style_column: string}>}>  $programs
     * @param  array<int, Collection<int, object>>  $rolesByProgram
     * @return array{byRoleIndex: array<string, string>, roles: array<int, object>}
     */
    private function indexColumnsByRole(array $programs, array $rolesByProgram): array
    {
        $byRoleIndex = [];
        $roles = [];

        foreach ($rolesByProgram as $programRoles) {
            foreach ($programRoles as $role) {
                $roles[(int) $role->id] = $role;
            }
        }

        foreach ($programs as $program) {
            foreach ($program['columns'] as $col) {
                $byRoleIndex[$col['role_id'].':'.$col['index']] = $col['key'];
            }
        }

        return ['byRoleIndex' => $byRoleIndex, 'roles' => $roles];
    }

    /**
     * @param  Collection<int, object>  $activities
     * @param  array{byRoleIndex: array<string, string>, roles: array<int, object>}  $columnIndex
     * @param  array<int, Collection<int, object>>  $rolesByProgram
     * @return list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>
     */
    private function placeActivities(Collection $activities, array $columnIndex, array $rolesByProgram): array
    {
        $placed = [];
        $byRoleIndex = $columnIndex['byRoleIndex'];

        foreach ($activities as $a) {
            $programId = (int) ($a->activity_first_program_id ?? 0);
            if ($programId < 1 || ! isset($rolesByProgram[$programId])) {
                continue;
            }

            $code = (string) ($a->activity_type_code ?? '');
            $text = trim((string) ($a->activity_name ?? ''));
            if ($text === '') {
                $text = '—';
            }

            $start = Carbon::parse($a->start_time);
            $end = Carbon::parse($a->end_time);
            $duration = max(self::SLOT_MINUTES, (int) $start->diffInMinutes($end));
            $rowspan = max(1, (int) ceil($duration / self::SLOT_MINUTES));
            // Snap display start to 5-minute grid floor
            $gridStart = $start->copy()->minute((int) (floor($start->minute / self::SLOT_MINUTES) * self::SLOT_MINUTES))->second(0);
            $activityId = (int) ($a->activity_id ?? 0);

            $programRoles = $rolesByProgram[$programId];
            $styleColumn = $this->styleColumnForProgram($programId);

            $lane = (int) ($a->lane ?? 0);
            if ($lane > 0) {
                foreach ($programRoles as $role) {
                    if ($role->differentiation_parameter !== 'lane') {
                        continue;
                    }
                    $key = $byRoleIndex[$role->id.':'.$lane] ?? null;
                    if ($key === null) {
                        continue;
                    }
                    $placed[] = [
                        'column_key' => $key,
                        'start' => $gridStart->copy(),
                        'end' => $end->copy(),
                        'text' => $text,
                        'rowspan' => $rowspan,
                        'style_column' => $styleColumn,
                        'activity_id' => $activityId,
                    ];
                }
            }

            foreach ([1, 2] as $ti) {
                $tableNo = (int) ($a->{'table_'.$ti} ?? 0);
                if ($tableNo < 1) {
                    continue;
                }

                $isCheck = $code === 'r_check';
                foreach ($programRoles as $role) {
                    if ($role->differentiation_parameter !== 'table') {
                        continue;
                    }
                    $isRcRole = $this->isRobotCheckRole($role);
                    if ($isCheck && ! $isRcRole) {
                        continue;
                    }
                    if (! $isCheck && $isRcRole) {
                        continue;
                    }
                    $key = $byRoleIndex[$role->id.':'.$tableNo] ?? null;
                    if ($key === null) {
                        continue;
                    }
                    $placed[] = [
                        'column_key' => $key,
                        'start' => $gridStart->copy(),
                        'end' => $end->copy(),
                        'text' => $text,
                        'rowspan' => $rowspan,
                        'style_column' => $isCheck ? 'Robot-Game' : (
                            $programId === FirstProgram::FUTURE_8->value ? 'Game' : 'Robot-Game'
                        ),
                        'activity_id' => $activityId,
                    ];
                }
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
            $endMinute = 55; // complete last hour on 5-min grid (12 rows)
            $dayStart = Carbon::createFromTime($startHour, 0, 0);
            $dayEnd = Carbon::createFromTime($endHour, $endMinute, 0);

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
