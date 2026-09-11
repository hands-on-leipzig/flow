<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use App\Models\Event;
use App\Models\EventNoticeHidden;
use App\Models\MNotice;
use App\Support\ProgramCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NoticeService
{
    public function __construct(
        private StaffingSyncService $staffingSync,
    ) {}

    /**
     * @return array{messages: list<array<string, mixed>>, dots: array<string, mixed>, restore_available: bool}
     */
    public function payload(Event $event): array
    {
        $event->loadMissing('programs.firstProgram');

        $conditions = $this->evaluateConditions($event);
        $hiddenIds = EventNoticeHidden::query()
            ->where('event', $event->id)
            ->pluck('notice')
            ->map(fn ($id) => (int) $id)
            ->all();
        $hiddenSet = array_fill_keys($hiddenIds, true);

        $today = Carbon::today(config('app.timezone'));
        $eventDate = $this->eventDate($event);

        $catalog = MNotice::query()
            ->with('helpScreen')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $redDot = [];
        $time = [];
        $restoreAvailable = false;

        foreach ($catalog as $row) {
            if ($row->kind === 'red_dot') {
                foreach ($this->instantiateRedDot($row, $event, $conditions) as $message) {
                    $redDot[] = $message;
                }

                continue;
            }

            if ($row->kind !== 'time') {
                continue;
            }

            if (! $this->inTimeWindow($row, $today, $eventDate)) {
                continue;
            }

            if (isset($hiddenSet[(int) $row->id])) {
                $restoreAvailable = true;

                continue;
            }

            $time[] = $this->singletonMessage($row, hideable: true);
        }

        usort($redDot, fn (array $a, array $b) => $this->sortMessages($a, $b, $catalog));
        usort($time, fn (array $a, array $b) => $this->sortMessages($a, $b, $catalog));

        return [
            'messages' => array_values(array_merge($redDot, $time)),
            'dots' => $this->dots($event, $conditions),
            'restore_available' => $restoreAvailable,
        ];
    }

    public function hide(Event $event, MNotice $notice): void
    {
        if ($notice->kind === 'red_dot') {
            abort(403, 'Red-dot notices cannot be hidden.');
        }

        EventNoticeHidden::query()->firstOrCreate([
            'event' => $event->id,
            'notice' => $notice->id,
        ]);
    }

    public function restore(Event $event): void
    {
        EventNoticeHidden::query()->where('event', $event->id)->delete();
    }

    /**
     * @return array{
     *     teams_by_program: array<string, bool>,
     *     teams_meta: array<string, array{slug: string, display: string, name: string}>,
     *     schedule_counts: bool,
     *     rooms_activities: bool,
     *     rooms_teams: bool,
     *     staffing_below_min: array<string, string>,
     *     staffing_surplus: bool
     * }
     */
    private function evaluateConditions(Event $event): array
    {
        $plan = DB::table('plan')->where('event', $event->id)->first();
        $draht = $this->drahtData($event);

        $teamsByProgram = [];
        $teamsMeta = [];
        foreach ($event->programs as $program) {
            $name = (string) $program->name;
            $compact = $this->programCompact($name);
            $teamsMeta[$compact] = [
                'slug' => $this->programSlug($name),
                'display' => ProgramCatalog::displayName($name, $name),
                'name' => $name,
            ];
            $teamsByProgram[$compact] = $this->programHasDiscrepancy($event, $program, $draht);
        }

        return [
            'teams_by_program' => $teamsByProgram,
            'teams_meta' => $teamsMeta,
            'schedule_counts' => $this->scheduleCountsMismatch($event, $plan, $draht),
            'rooms_activities' => ! $this->roomsActivitiesOk($plan),
            'rooms_teams' => ! $this->roomsTeamsOk($event, $plan),
            'staffing_below_min' => $this->staffingBelowMinScopes($event),
            'staffing_surplus' => $this->hasStaffingSurplus((int) $event->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return list<array<string, mixed>>
     */
    private function instantiateRedDot(MNotice $row, Event $event, array $conditions): array
    {
        $key = (string) ($row->condition_key ?: $row->key);

        return match ($key) {
            'teams_discrepancy' => $this->teamsMessages($row, $conditions),
            'schedule_counts' => ($conditions['schedule_counts'] ?? false)
                ? [$this->singletonMessage($row, hideable: false)]
                : [],
            'rooms_activities' => ($conditions['rooms_activities'] ?? false)
                ? [$this->singletonMessage($row, hideable: false)]
                : [],
            'rooms_teams' => ($conditions['rooms_teams'] ?? false)
                ? [$this->singletonMessage($row, hideable: false)]
                : [],
            'staffing_below_min' => $this->staffingBelowMinMessages($row, $conditions),
            'staffing_surplus' => ($conditions['staffing_surplus'] ?? false)
                ? [$this->singletonMessage($row, hideable: false)]
                : [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return list<array<string, mixed>>
     */
    private function teamsMessages(MNotice $row, array $conditions): array
    {
        $messages = [];
        $byProgram = $conditions['teams_by_program'] ?? [];
        $meta = $conditions['teams_meta'] ?? [];

        foreach ($byProgram as $compact => $on) {
            if (! $on) {
                continue;
            }
            $info = $meta[$compact] ?? ['slug' => $compact, 'display' => $compact, 'name' => $compact];
            $messages[] = $this->message($row, [
                'id' => $row->id.':'.$compact,
                'body' => str_replace('{program}', (string) $info['display'], (string) $row->body),
                'jump_path' => '/plan/teams/'.$info['slug'],
                'hideable' => false,
                'program' => $info['slug'],
                'scope' => null,
            ]);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return list<array<string, mixed>>
     */
    private function staffingBelowMinMessages(MNotice $row, array $conditions): array
    {
        $messages = [];
        $scopes = $conditions['staffing_below_min'] ?? [];
        foreach ($scopes as $scopeKey => $label) {
            $messages[] = $this->message($row, [
                'id' => $row->id.':'.$scopeKey,
                'body' => str_replace('{scope}', (string) $label, (string) $row->body),
                'hideable' => false,
                'program' => null,
                'scope' => (string) $scopeKey,
            ]);
        }

        return $messages;
    }

    private function singletonMessage(MNotice $row, bool $hideable): array
    {
        return $this->message($row, [
            'id' => (string) $row->id,
            'body' => (string) $row->body,
            'hideable' => $hideable,
            'program' => null,
            'scope' => null,
        ]);
    }

    /**
     * @param  array{id?: string, body?: string, jump_path?: string, hideable: bool, program: ?string, scope: ?string}  $override
     * @return array<string, mixed>
     */
    private function message(MNotice $row, array $override): array
    {
        $screen = $row->helpScreen;

        return [
            'id' => $override['id'] ?? (string) $row->id,
            'notice_id' => (int) $row->id,
            'kind' => $row->kind,
            'key' => $row->key,
            'condition_key' => $row->condition_key,
            'body' => $override['body'] ?? (string) $row->body,
            'screen_key' => $screen?->key,
            'jump_path' => $override['jump_path'] ?? ($screen?->route_path ?: null),
            'hideable' => $override['hideable'],
            'program' => $override['program'],
            'scope' => $override['scope'],
        ];
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function dots(Event $event, array $conditions): array
    {
        $teamsByProgram = [];
        foreach ($event->programs as $program) {
            $compact = $this->programCompact((string) $program->name);
            $teamsByProgram[$compact] = (bool) ($conditions['teams_by_program'][$compact] ?? false);
        }

        $roomsActivities = (bool) ($conditions['rooms_activities'] ?? false);
        $roomsTeams = (bool) ($conditions['rooms_teams'] ?? false);
        $belowMin = $conditions['staffing_below_min'] ?? [];
        $surplus = (bool) ($conditions['staffing_surplus'] ?? false);

        return [
            'schedule' => (bool) ($conditions['schedule_counts'] ?? false),
            'rooms' => $roomsActivities || $roomsTeams,
            'rooms_activities' => $roomsActivities,
            'rooms_teams' => $roomsTeams,
            'volunteers_staffing' => $belowMin !== [] || $surplus,
            'teams_by_program' => $teamsByProgram,
        ];
    }

    private function inTimeWindow(MNotice $row, Carbon $today, ?Carbon $eventDate): bool
    {
        if ($row->time_mode === 'absolute') {
            if ($row->abs_start === null || $row->abs_end === null) {
                return false;
            }
            $start = Carbon::parse($row->abs_start, config('app.timezone'))->startOfDay();
            $end = Carbon::parse($row->abs_end, config('app.timezone'))->startOfDay();

            return $today->gte($start) && $today->lte($end);
        }

        if ($row->time_mode === 'relative') {
            if ($eventDate === null || $row->rel_start_days === null || $row->rel_end_days === null) {
                return false;
            }
            $start = $eventDate->copy()->addDays((int) $row->rel_start_days);
            $end = $eventDate->copy()->addDays((int) $row->rel_end_days);

            return $today->gte($start) && $today->lte($end);
        }

        return false;
    }

    private function eventDate(Event $event): ?Carbon
    {
        if (! $event->date) {
            return null;
        }

        return Carbon::parse($event->date, config('app.timezone'))->startOfDay();
    }

    private function drahtData(Event $event): array
    {
        try {
            $response = app(DrahtController::class)->show($event);
            $data = $response->getData(true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::error('NoticeService DRAHT fetch failed for event '.$event->id.': '.$e->getMessage());

            return ['programs' => []];
        }
    }

    private function programHasDiscrepancy(Event $event, $program, array $draht): bool
    {
        $localTeams = $this->normalizeTeamList(
            DB::table('team')
                ->where('event', $event->id)
                ->where('first_program', $program->first_program)
                ->get(['team_number_hot', 'name'])
                ->map(fn ($row) => ['team_number_hot' => $row->team_number_hot, 'name' => $row->name])
                ->all()
        );

        $drahtProgram = collect($draht['programs'] ?? [])
            ->first(function ($row) use ($program) {
                if ((int) ($row['first_program'] ?? 0) === (int) $program->first_program) {
                    return true;
                }

                return strcasecmp((string) ($row['name'] ?? ''), (string) $program->name) === 0;
            });

        $drahtTeams = $this->normalizeTeamList($drahtProgram['teams'] ?? []);

        return $this->hasDiscrepancy($localTeams, $drahtTeams);
    }

    private function normalizeTeamList(mixed $teams): array
    {
        if (! is_array($teams)) {
            return [];
        }

        $isList = array_is_list($teams);
        $items = $isList ? $teams : array_values($teams);

        return array_values(array_filter($items, fn ($team) => is_array($team)));
    }

    private function hasDiscrepancy(array $localTeams, array $drahtTeams): bool
    {
        $normalizeTeamNumber = function ($num) {
            if ($num == null || $num === '' || $num === 0) {
                return null;
            }
            $normalized = (int) $num;

            return ($normalized === 0) ? null : $normalized;
        };

        $localMap = [];
        foreach ($localTeams as $team) {
            $num = $normalizeTeamNumber($team['team_number_hot'] ?? null);
            if ($num != null) {
                $localMap[$num] = $team;
            }
        }

        $drahtMap = [];
        foreach ($drahtTeams as $team) {
            $num = $normalizeTeamNumber($team['ref'] ?? $team['number'] ?? null);
            if ($num != null) {
                $drahtMap[$num] = $team;
            }
        }

        $allNumbers = array_unique(array_merge(array_keys($localMap), array_keys($drahtMap)));
        foreach ($allNumbers as $number) {
            $local = $localMap[$number] ?? null;
            $draht = $drahtMap[$number] ?? null;
            if ($local && $draht && ($local['name'] ?? '') !== ($draht['name'] ?? '')) {
                return true;
            }
            if ($draht && ! $local) {
                return true;
            }
            if ($local && ! $draht) {
                return true;
            }
        }

        return false;
    }

    private function scheduleCountsMismatch(Event $event, mixed $plan, array $draht): bool
    {
        if (! $plan) {
            return false;
        }

        $paramIds = DB::table('m_parameter')
            ->whereIn('name', ['c_teams', 'e_teams', 'f8_teams'])
            ->pluck('id', 'name');

        $values = DB::table('plan_param_value')
            ->where('plan', $plan->id)
            ->whereIn('parameter', $paramIds->values())
            ->pluck('set_value', 'parameter')
            ->map(fn ($v) => (int) $v);

        $plannedChallengeTeams = $values[$paramIds['c_teams'] ?? null] ?? 0;
        $plannedExploreTeams = $values[$paramIds['e_teams'] ?? null] ?? 0;
        $plannedFutureTeams = $values[$paramIds['f8_teams'] ?? null] ?? 0;

        $drahtPrograms = collect($draht['programs'] ?? []);
        $countFor = function (callable $match) use ($drahtPrograms): int {
            $row = $drahtPrograms->first($match);
            $teams = $row['teams'] ?? [];

            return is_array($teams) ? count($teams) : 0;
        };

        $registeredExploreTeams = $countFor(
            fn ($p) => strcasecmp((string) ($p['name'] ?? ''), ProgramCatalog::EXPLORE) === 0
        );
        $registeredChallengeTeams = $countFor(
            fn ($p) => strcasecmp((string) ($p['name'] ?? ''), ProgramCatalog::CHALLENGE) === 0
        );
        $registeredFutureTeams = $countFor(
            fn ($p) => ProgramCatalog::isFuture($p['name'] ?? null)
        );

        $hasExplore = ProgramCatalog::hasExplore($event);
        $hasChallenge = ProgramCatalog::hasChallenge($event);
        $hasFuture = ProgramCatalog::hasFuture($event);

        $exploreOk = $hasExplore ? ($plannedExploreTeams === $registeredExploreTeams) : true;
        $challengeOk = $hasChallenge ? ($plannedChallengeTeams === $registeredChallengeTeams) : true;
        $futureOk = $hasFuture ? ($plannedFutureTeams === $registeredFutureTeams) : true;

        return ! ($exploreOk && $challengeOk && $futureOk);
    }

    private function roomsActivitiesOk(mixed $plan): bool
    {
        if (! $plan) {
            return false;
        }

        try {
            $response = app(PlanRoomTypeController::class)->unmappedRoomTypes((int) $plan->id);
            $unmapped = $response->getData(true);

            return empty($unmapped);
        } catch (\Throwable $e) {
            Log::error('NoticeService room activity mapping failed: '.$e->getMessage());

            return false;
        }
    }

    private function roomsTeamsOk(Event $event, mixed $plan): bool
    {
        if (! $plan) {
            return false;
        }

        foreach ($event->programs as $program) {
            $teams = DB::table('team')
                ->leftJoin('team_plan', function ($join) use ($plan) {
                    $join->on('team.id', '=', 'team_plan.team')
                        ->where('team_plan.plan', '=', $plan->id);
                })
                ->where('team.event', $event->id)
                ->where('team.first_program', $program->first_program)
                ->select('team.id', 'team_plan.room')
                ->get();

            $withoutRoom = $teams->whereNull('room')->count();
            if (! $teams->isEmpty() && $withoutRoom > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function staffingBelowMinScopes(Event $event): array
    {
        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $summary = $this->staffingSync->summaryByScope((int) $event->id, $programIds);
        $scopes = [];
        foreach ($summary as $row) {
            if ((int) ($row['missing_min'] ?? 0) <= 0) {
                continue;
            }
            $key = (string) $row['key'];
            $scopes[$key] = $this->scopeLabel($key, $event);
        }

        return $scopes;
    }

    private function hasStaffingSurplus(int $eventId): bool
    {
        $roles = \App\Models\EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['groups.assignments', 'assignments'])
            ->get();

        if ($roles->isEmpty()) {
            return false;
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

    private function scopeLabel(string $scopeKey, Event $event): string
    {
        if ($scopeKey === 'cross') {
            return 'Übergreifend';
        }
        if ($scopeKey === 'local') {
            return 'Zusätzlich';
        }
        if (str_starts_with($scopeKey, 'program:')) {
            $id = (int) substr($scopeKey, strlen('program:'));
            $program = $event->programs->firstWhere('first_program', $id);
            $name = (string) ($program?->name ?? '');

            return ProgramCatalog::displayName($name !== '' ? $name : $id, $name);
        }

        return $scopeKey;
    }

    private function programSlug(?string $name): string
    {
        return str_replace('-', '_', strtolower((string) $name));
    }

    private function programCompact(?string $name): string
    {
        return str_replace('_', '', $this->programSlug($name));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MNotice>  $catalog
     */
    private function sortMessages(array $a, array $b, $catalog): int
    {
        $orderA = (int) ($catalog->firstWhere('id', $a['notice_id'])?->sort_order ?? 0);
        $orderB = (int) ($catalog->firstWhere('id', $b['notice_id'])?->sort_order ?? 0);
        if ($orderA !== $orderB) {
            return $orderA <=> $orderB;
        }

        return ((int) $a['notice_id']) <=> ((int) $b['notice_id']);
    }
}
