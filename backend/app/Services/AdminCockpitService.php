<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Models\EventStaffingRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminCockpitService
{
    private const TEAM_PROGRAM_IDS = [
        FirstProgram::EXPLORE->value,
        FirstProgram::CHALLENGE->value,
        FirstProgram::FUTURE_8->value,
    ];

    private const TEAM_PARAM_BY_PROGRAM = [
        FirstProgram::EXPLORE->value => 'e_teams',
        FirstProgram::CHALLENGE->value => 'c_teams',
        FirstProgram::FUTURE_8->value => 'f8_teams',
    ];

    public function __construct(
        private EventTitleService $eventTitles,
        private RoomTypeFetcherService $roomTypes,
        private StaffingSyncService $staffingSync,
    ) {}

    public function resolveSeasonId(mixed $raw): int
    {
        $id = (int) $raw;
        if ($id > 0 && DB::table('m_season')->where('id', $id)->exists()) {
            return $id;
        }

        return SeasonService::currentSeasonId();
    }

    /**
     * @return array{season_id: int, events: list<array<string, mixed>>}
     */
    public function payload(int $seasonId): array
    {
        $rows = DB::table('event')
            ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
            ->leftJoin('plan', 'plan.event', '=', 'event.id')
            ->where('event.season', $seasonId)
            ->where('regional_partner.name', 'not like', '%QPlan RP%')
            ->orderBy('event.date')
            ->orderBy('regional_partner.id')
            ->get([
                'event.id as event_id',
                'event.name as event_name',
                'event.level as event_level',
                'event.date as event_date',
                'regional_partner.id as regional_partner_id',
                'regional_partner.name as regional_partner_name',
                'plan.id as plan_id',
                'event.public_helper_search as public_helper_search',
            ]);

        $eventIds = $rows->pluck('event_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $planIds = $rows->pluck('plan_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $programsByEvent = $this->programsByEvent($eventIds);
        $teamCounts = $this->teamCounts($eventIds);
        $helferliste = $this->helferlisteCounts($eventIds);
        $generatorStats = $this->generatorStats($planIds);
        $accessCounts = $this->accessCounts($eventIds);
        $paramChanges = $this->paramChanges($planIds);
        $extraBlocks = $this->extraBlockCounts($planIds);
        $publications = $this->publicationLevels($eventIds);
        $plannedTeams = $this->plannedTeamCounts($planIds);
        $roomsTeamsRed = $this->roomsTeamsRedByEvent($eventIds);

        $events = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event_id;
            $planId = $row->plan_id !== null ? (int) $row->plan_id : null;
            $attached = $programsByEvent[$eventId] ?? [];
            $attachedIds = array_map(fn ($p) => (int) $p['id'], $attached);

            $teams = [];
            foreach (self::TEAM_PROGRAM_IDS as $programId) {
                $key = (string) $programId;
                if (! in_array($programId, $attachedIds, true)) {
                    $teams[$key] = null;
                    continue;
                }
                $teams[$key] = (int) ($teamCounts[$eventId][$programId] ?? 0);
            }

            $hasPlan = $planId !== null;
            $eventDate = $this->isoDate($row->event_date);
            $gen = $hasPlan ? ($generatorStats[$planId] ?? null) : null;

            $events[] = [
                'event_id' => $eventId,
                'regional_partner_id' => $row->regional_partner_id !== null ? (int) $row->regional_partner_id : null,
                'regional_partner_name' => $row->regional_partner_name,
                'event_date' => $eventDate,
                'event_name' => $this->eventTitles->getEventTitleShort((object) [
                    'name' => $row->event_name,
                    'level' => $row->event_level ?? 0,
                    'programs' => array_map(fn ($p) => (object) ['name' => $p['name']], $attached),
                ]),
                'plan_id' => $planId,
                'programs' => $attachedIds,
                'teams' => $teams,
                'dots' => [
                    'plan' => $this->planDot($hasPlan, $attachedIds, $teams, $plannedTeams[$planId] ?? []),
                    'team' => null,
                    'rooms' => $this->roomsDot(
                        $hasPlan,
                        $planId,
                        $eventDate,
                        $attachedIds,
                        $roomsTeamsRed[$eventId] ?? [],
                    ),
                    'staffing' => $this->staffingDot($eventId, $attachedIds),
                ],
                'generator_last_end' => $gen['last_end'] ?? null,
                'generator_count' => $hasPlan ? (int) ($gen['count'] ?? 0) : null,
                'param_changes' => $hasPlan ? ($paramChanges[$planId] ?? ['input' => 0, 'expert' => 0]) : null,
                'extra_blocks' => $hasPlan
                    ? ($extraBlocks[$planId] ?? ['free' => 0, 'slot' => 0])
                    : null,
                'helferliste_count' => (int) ($helferliste[$eventId] ?? 0),
                'public_helper_search' => (bool) ($row->public_helper_search ?? false),
                'publication_level' => $hasPlan ? ($publications[$eventId] ?? null) : null,
                'access_count' => (int) ($accessCounts[$eventId] ?? 0),
            ];
        }

        return [
            'season_id' => $seasonId,
            'events' => $events,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<int>  $programIds
     * @return list<array<string, mixed>>
     */
    public function filterUpcoming(array $events, bool $upcoming, ?Carbon $today = null): array
    {
        if (! $upcoming) {
            return $events;
        }

        $today = ($today ?? Carbon::today(config('app.timezone')))->toDateString();

        return array_values(array_filter(
            $events,
            static function (array $row) use ($today): bool {
                $date = $row['event_date'] ?? null;
                if ($date === null || $date === '') {
                    return true;
                }

                return (string) $date >= $today;
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<int>  $programIds
     * @return list<array<string, mixed>>
     */
    public function filterPrograms(array $events, array $programIds): array
    {
        $wanted = array_values(array_unique(array_filter(
            array_map('intval', $programIds),
            static fn (int $id) => $id > 0,
        )));
        if ($wanted === []) {
            return [];
        }

        return array_values(array_filter(
            $events,
            static function (array $row) use ($wanted): bool {
                $have = array_map('intval', $row['programs'] ?? []);

                return array_intersect($wanted, $have) !== [];
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function filterWithoutPlan(array $events, bool $withoutPlan): array
    {
        if (! $withoutPlan) {
            return $events;
        }

        return array_values(array_filter(
            $events,
            static function (array $row): bool {
                $end = $row['generator_last_end'] ?? null;

                return $end === null || $end === '';
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function filterHelferliste(array $events, string $mode): array
    {
        $mode = match ($mode) {
            'empty', 'filled' => $mode,
            default => 'both',
        };
        if ($mode === 'both') {
            return $events;
        }

        $wantEmpty = $mode === 'empty';

        return array_values(array_filter(
            $events,
            static function (array $row) use ($wantEmpty): bool {
                $empty = (int) ($row['helferliste_count'] ?? 0) === 0;

                return $wantEmpty ? $empty : ! $empty;
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function sortEvents(array $events, string $sort, string $dir): array
    {
        $dir = strtolower($dir) === 'desc' ? -1 : 1;
        $sort = match ($sort) {
            'rp', 'generator', 'publish' => $sort,
            default => 'date',
        };

        usort($events, function (array $a, array $b) use ($sort, $dir): int {
            $cmp = match ($sort) {
                'rp' => $this->compareNullableString($a['regional_partner_name'] ?? null, $b['regional_partner_name'] ?? null),
                'generator' => $this->compareNullableString($a['generator_last_end'] ?? null, $b['generator_last_end'] ?? null),
                'publish' => $this->compareNullableInt($a['publication_level'] ?? null, $b['publication_level'] ?? null),
                default => $this->compareNullableString($a['event_date'] ?? null, $b['event_date'] ?? null),
            };
            if ($cmp !== 0) {
                return $cmp * $dir;
            }

            return $this->compareNullableInt($a['regional_partner_id'] ?? null, $b['regional_partner_id'] ?? null);
        });

        return $events;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function programsByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('event_program')
            ->leftJoin('m_first_program', 'm_first_program.id', '=', 'event_program.first_program')
            ->whereIn('event_program.event', $eventIds)
            ->orderBy('event_program.event')
            ->orderBy('m_first_program.sequence')
            ->orderBy('event_program.first_program')
            ->get([
                'event_program.event',
                'event_program.first_program',
                'm_first_program.name',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event;
            $programId = (int) $row->first_program;
            if ($programId <= 0) {
                continue;
            }
            $out[$eventId][] = [
                'id' => $programId,
                'name' => (string) ($row->name ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array<int, int>>
     */
    private function teamCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('team')
            ->whereIn('event', $eventIds)
            ->select('event', 'first_program', DB::raw('COUNT(*) as count'))
            ->groupBy('event', 'first_program')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->event][(int) $row->first_program] = (int) $row->count;
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function helferlisteCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('event_volunteer_roster')
            ->whereIn('event', $eventIds)
            ->select('event', DB::raw('COUNT(*) as count'))
            ->groupBy('event')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->event] = (int) $row->count;
        }

        return $out;
    }

    /**
     * @param  list<int>  $planIds
     * @return array<int, array{last_end: string, count: int}>
     */
    private function generatorStats(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $rows = DB::table('s_generator')
            ->whereIn('plan', $planIds)
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->select('plan', DB::raw('MAX(end) as last_end'), DB::raw('COUNT(*) as count'))
            ->groupBy('plan')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row->last_end === null || $row->last_end === '') {
                continue;
            }
            $out[(int) $row->plan] = [
                'last_end' => Carbon::parse($row->last_end, config('app.timezone'))->toIso8601String(),
                'count' => (int) $row->count,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function accessCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('s_one_link_access')
            ->whereIn('event', $eventIds)
            ->select('event', DB::raw('COUNT(*) as count'))
            ->groupBy('event')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->event] = (int) $row->count;
        }

        return $out;
    }

    /**
     * @param  list<int>  $planIds
     * @return array<int, array{input: int, expert: int}>
     */
    private function paramChanges(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $rows = DB::table('plan_param_value as ppv')
            ->join('m_parameter as mp', 'mp.id', '=', 'ppv.parameter')
            ->whereIn('ppv.plan', $planIds)
            ->where(function ($q) {
                $q->where('mp.context', 'expert')
                    ->orWhere(function ($q2) {
                        $q2->where('mp.context', 'input')
                            ->where(function ($q3) {
                                $q3->where('mp.name', 'like', '%duration%')
                                    ->orWhere('mp.name', 'like', '%start%');
                            });
                    });
            })
            ->where(function ($q) {
                $q->whereRaw('ppv.set_value <> mp.value')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('ppv.set_value')->whereNotNull('mp.value');
                    })
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('ppv.set_value')->whereNull('mp.value');
                    });
            })
            ->select('ppv.plan', 'mp.context', DB::raw('COUNT(*) as count'))
            ->groupBy('ppv.plan', 'mp.context')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $planId = (int) $row->plan;
            if (! isset($out[$planId])) {
                $out[$planId] = ['input' => 0, 'expert' => 0];
            }
            $context = (string) $row->context;
            if ($context === 'input' || $context === 'expert') {
                $out[$planId][$context] = (int) $row->count;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $planIds
     * @return array<int, array{free: int, slot: int}>
     */
    private function extraBlockCounts(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $rows = DB::table('extra_block')
            ->whereIn('plan', $planIds)
            ->where('active', 1)
            ->whereIn('type', ['free', 'slot'])
            ->select('plan', 'type', DB::raw('COUNT(*) as count'))
            ->groupBy('plan', 'type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $planId = (int) $row->plan;
            if (! isset($out[$planId])) {
                $out[$planId] = ['free' => 0, 'slot' => 0];
            }
            $type = (string) $row->type;
            if ($type === 'free' || $type === 'slot') {
                $out[$planId][$type] = (int) $row->count;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function publicationLevels(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('publication')
            ->whereIn('event', $eventIds)
            ->orderByDesc('last_change')
            ->orderByDesc('id')
            ->get(['event', 'level', 'last_change', 'id']);

        $out = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event;
            if (isset($out[$eventId])) {
                continue;
            }
            $out[$eventId] = $row->level !== null ? (int) $row->level : null;
        }

        return $out;
    }

    /**
     * @param  list<int>  $planIds
     * @return array<int, array<int, int>>
     */
    private function plannedTeamCounts(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $paramIds = DB::table('m_parameter')
            ->whereIn('name', array_values(self::TEAM_PARAM_BY_PROGRAM))
            ->pluck('id', 'name');

        if ($paramIds->isEmpty()) {
            return [];
        }

        $nameById = $paramIds->flip();
        $programByParamName = array_flip(self::TEAM_PARAM_BY_PROGRAM);

        $rows = DB::table('plan_param_value')
            ->whereIn('plan', $planIds)
            ->whereIn('parameter', $paramIds->values())
            ->get(['plan', 'parameter', 'set_value']);

        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($nameById[(int) $row->parameter] ?? '');
            $programId = $programByParamName[$name] ?? null;
            if ($programId === null) {
                continue;
            }
            $out[(int) $row->plan][(int) $programId] = (int) $row->set_value;
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array<int, bool>>  event → first_program → red
     */
    private function roomsTeamsRedByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('team')
            ->leftJoin('plan', 'plan.event', '=', 'team.event')
            ->leftJoin('team_plan', function ($join) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->on('team_plan.plan', '=', 'plan.id');
            })
            ->whereIn('team.event', $eventIds)
            ->get([
                'team.event',
                'team.first_program',
                'team.id as team_id',
                'team_plan.room',
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event;
            $programId = (int) $row->first_program;
            $grouped[$eventId][$programId]['total'] = ($grouped[$eventId][$programId]['total'] ?? 0) + 1;
            if ($row->room === null) {
                $grouped[$eventId][$programId]['without'] = ($grouped[$eventId][$programId]['without'] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($grouped as $eventId => $programs) {
            foreach ($programs as $programId => $stats) {
                $out[$eventId][$programId] = ($stats['total'] ?? 0) > 0 && ($stats['without'] ?? 0) > 0;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $attachedIds
     * @param  array<string, int|null>  $teams
     * @param  array<int, int>  $planned
     */
    private function planDot(bool $hasPlan, array $attachedIds, array $teams, array $planned): bool
    {
        if (! $hasPlan) {
            return true;
        }

        foreach ($attachedIds as $programId) {
            if (! isset(self::TEAM_PARAM_BY_PROGRAM[$programId])) {
                continue;
            }
            $local = (int) ($teams[(string) $programId] ?? 0);
            $want = (int) ($planned[$programId] ?? 0);
            if ($local !== $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $attachedIds
     * @param  array<int, bool>  $roomsTeamsRed
     */
    private function roomsDot(
        bool $hasPlan,
        ?int $planId,
        ?string $eventDate,
        array $attachedIds,
        array $roomsTeamsRed,
    ): bool {
        if (! $hasPlan || $planId === null) {
            return true;
        }

        if ($this->hasUnmappedRoomTypes($planId, $eventDate)) {
            return true;
        }

        foreach ($attachedIds as $programId) {
            if ($roomsTeamsRed[$programId] ?? false) {
                return true;
            }
        }

        return false;
    }

    private function hasUnmappedRoomTypes(int $planId, ?string $eventDate): bool
    {
        try {
            $groups = $this->roomTypes->fetchRoomTypes($planId);
        } catch (\Throwable $e) {
            Log::error('AdminCockpitService room types failed for plan '.$planId.': '.$e->getMessage());

            return true;
        }

        $mappedNormal = DB::table('room_type_room')
            ->join('plan', 'plan.event', '=', 'room_type_room.event')
            ->where('plan.id', $planId)
            ->pluck('room_type_room.room_type')
            ->all();

        $mappedExtrasQuery = DB::table('extra_block')
            ->where('plan', $planId)
            ->where(function ($q) use ($eventDate) {
                $q->whereNotNull('room');
                if ($eventDate) {
                    $q->orWhereDate('start', '<', $eventDate);
                }
            });
        $mappedExtras = $mappedExtrasQuery->pluck('id')->all();

        foreach ($groups as $group) {
            $isExtraGroup = ((int) ($group['id'] ?? 0)) === 999;
            foreach ($group['room_types'] ?? [] as $rt) {
                $typeId = $rt['type_id'] ?? null;
                if ($typeId === null) {
                    continue;
                }
                $mapped = $isExtraGroup
                    ? in_array($typeId, $mappedExtras, false)
                    : in_array($typeId, $mappedNormal, false);
                if (! $mapped) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $programIds
     */
    private function staffingDot(int $eventId, array $programIds): bool
    {
        $roles = EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['groups.assignments', 'assignments', 'catalogRole'])
            ->get();

        if ($roles->isEmpty()) {
            return false;
        }

        $summary = $this->staffingSync->summaryByScope($eventId, $programIds);
        foreach ($summary as $row) {
            if ((int) ($row['missing_min'] ?? 0) > 0) {
                return true;
            }
        }

        foreach ($roles as $role) {
            if ($role->isGrouped()) {
                foreach ($role->groups as $group) {
                    if ($group->surplus && $group->assignments->count() > 0) {
                        return true;
                    }
                }

                continue;
            }
            if ($role->surplus && $role->assignments->count() > 0) {
                return true;
            }
        }

        return false;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))->toDateString();
    }

    private function compareNullableInt(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return (int) $a <=> (int) $b;
    }

    private function compareNullableString(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return strcmp((string) $a, (string) $b);
    }
}
