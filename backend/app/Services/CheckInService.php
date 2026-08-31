<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Plan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckInService
{
    public const ORGANIZER_M_ROLE = 31;

    public function __construct(
        private PublicPlanService $publicPlan,
    ) {}

    public function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function encryptPin(string $pin): string
    {
        return Crypt::encryptString($pin);
    }

    public function decryptPin(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ensurePin(Event $event): string
    {
        $pin = $this->decryptPin($event->check_in_pin);
        if ($pin !== null && preg_match('/^\d{6}$/', $pin)) {
            return $pin;
        }

        $pin = $this->generatePin();
        $event->check_in_pin = $this->encryptPin($pin);
        $event->save();

        return $pin;
    }

    public function settingsPayload(Event $event): array
    {
        $pin = $this->ensurePin($event);

        return [
            'event_id' => $event->id,
            'slug' => $event->slug,
            'has_slug' => $event->slug !== null && $event->slug !== '',
            'enabled' => (bool) $event->check_in_enabled,
            'pin' => $pin,
            'text_teams' => $event->check_in_text_teams,
            'text_helpers' => $event->check_in_text_helpers,
            'reception_path' => $event->slug ? '/'.$event->slug.'/check-in' : null,
        ];
    }

    public function updateSettings(Event $event, array $data): array
    {
        if (array_key_exists('enabled', $data)) {
            $event->check_in_enabled = (bool) $data['enabled'];
        }

        if (array_key_exists('pin', $data)) {
            $pin = preg_replace('/\D/', '', (string) $data['pin']);
            if (strlen($pin) !== 6) {
                throw new \InvalidArgumentException('PIN must be exactly 6 digits.');
            }
            $event->check_in_pin = $this->encryptPin($pin);
        }

        if (array_key_exists('text_teams', $data)) {
            $event->check_in_text_teams = $data['text_teams'];
        }

        if (array_key_exists('text_helpers', $data)) {
            $event->check_in_text_helpers = $data['text_helpers'];
        }

        if ($event->check_in_enabled) {
            $this->ensurePin($event);
        }

        $event->save();

        return $this->settingsPayload($event->fresh());
    }

    public function verifyPin(Event $event, string $pin): bool
    {
        $expected = $this->decryptPin($event->check_in_pin);
        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, preg_replace('/\D/', '', $pin) ?? '');
    }

    public function makeSessionToken(Event $event): string
    {
        return Crypt::encryptString(json_encode([
            'event_id' => (int) $event->id,
            'issued_at' => now()->toIso8601String(),
        ]));
    }

    public function eventIdFromSessionToken(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
            if (! is_array($payload) || ! isset($payload['event_id'])) {
                return null;
            }

            return (int) $payload['event_id'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function resetRecords(Event $event): int
    {
        return CheckIn::query()->where('event', $event->id)->delete();
    }

    public function findRecord(Event $event, string $subjectType, int $subjectId): ?CheckIn
    {
        return CheckIn::query()
            ->where('event', $event->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();
    }

    public function recordStatusPayload(?CheckIn $record): array
    {
        if (! $record) {
            return [
                'status' => null,
                'checked_in_at' => null,
                'reception_note' => null,
                'no_show_reason' => null,
                'no_show_source' => null,
            ];
        }

        return [
            'status' => $record->status,
            'checked_in_at' => $record->checked_in_at?->timezone('Europe/Berlin')->format('Y-m-d H:i'),
            'reception_note' => $record->reception_note,
            'no_show_reason' => $record->no_show_reason,
            'no_show_source' => $record->no_show_source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(Event $event, string $query): array
    {
        $q = mb_strtolower(trim($query));
        if (mb_strlen($q) < 2) {
            return [];
        }

        $records = CheckIn::query()
            ->where('event', $event->id)
            ->get()
            ->keyBy(fn (CheckIn $row) => $row->subject_type.':'.$row->subject_id);

        $results = [];

        foreach ($this->searchTeams($event, $q) as $hit) {
            $key = CheckIn::SUBJECT_TEAM.':'.$hit['id'];
            $results[] = array_merge($hit, [
                'subject_type' => CheckIn::SUBJECT_TEAM,
                'subject_id' => $hit['id'],
            ], $this->recordStatusPayload($records->get($key)));
        }

        foreach ($this->searchHelpers($event, $q) as $hit) {
            $key = CheckIn::SUBJECT_VOLUNTEER.':'.$hit['id'];
            $results[] = array_merge($hit, [
                'subject_type' => CheckIn::SUBJECT_VOLUNTEER,
                'subject_id' => $hit['id'],
            ], $this->recordStatusPayload($records->get($key)));
        }

        usort($results, function (array $a, array $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return array_slice($results, 0, 40);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchTeams(Event $event, string $q): array
    {
        $plan = Plan::query()->where('event', $event->id)->orderBy('id')->first();
        $coachHits = $this->coachTeamIdsMatching($event, $q);

        $rows = DB::table('team')
            ->leftJoin('team_plan', function ($join) use ($plan) {
                $join->on('team_plan.team', '=', 'team.id');
                if ($plan) {
                    $join->where('team_plan.plan', '=', $plan->id);
                }
            })
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'team.first_program')
            ->where('team.event', $event->id)
            ->select([
                'team.id',
                'team.name',
                'team.team_number_hot',
                'team.location',
                'team.organization',
                'team.first_program',
                'team_plan.team_number_plan',
                'fp.name as program_name',
                'fp.logo_stem',
            ])
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row->name,
                $row->team_number_hot,
                $row->team_number_plan,
                $row->location,
                $row->organization,
                $row->program_name,
            ])));

            $matchedViaCoach = in_array((int) $row->id, $coachHits, true);
            if (! str_contains($haystack, $q) && ! $matchedViaCoach) {
                continue;
            }

            $label = trim((string) ($row->name ?? ''));

            $hits[] = [
                'id' => (int) $row->id,
                'label' => $label !== '' ? $label : ('Team '.$row->id),
                'subtitle' => 'Team',
                'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
                'program_name' => $row->program_name,
                'logo_stem' => $row->logo_stem ?: null,
            ];
        }

        return $hits;
    }

    /**
     * @return list<int>
     */
    private function coachTeamIdsMatching(Event $event, string $q): array
    {
        $peopleByHot = $this->drahtPeopleByHotNumber($event);
        if ($peopleByHot === []) {
            return [];
        }

        $hotNumbers = [];
        foreach ($peopleByHot as $hot => $people) {
            $blob = mb_strtolower(json_encode($people, JSON_UNESCAPED_UNICODE) ?: '');
            if (str_contains($blob, $q)) {
                $hotNumbers[] = (string) $hot;
            }
        }

        if ($hotNumbers === []) {
            return [];
        }

        return DB::table('team')
            ->where('event', $event->id)
            ->whereIn('team_number_hot', $hotNumbers)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function drahtPeopleByHotNumber(Event $event): array
    {
        return Cache::remember('check-in-draht-people-'.$event->id, 120, function () use ($event) {
            $merged = [];
            $event->loadMissing('programs');
            $draht = app(DrahtController::class);

            foreach ($event->programs as $program) {
                $drahtId = $program->draht_id ?? null;
                if (! $drahtId) {
                    continue;
                }
                try {
                    $response = $draht->getPeople((int) $drahtId);
                    $data = $response->getData(true);
                    if (! is_array($data) || isset($data['error'])) {
                        continue;
                    }
                    foreach ($data as $key => $value) {
                        if (in_array($key, ['total_players', 'total_coaches'], true)) {
                            continue;
                        }
                        if (is_array($value)) {
                            $merged[(string) $key] = $value;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Check-in DRAHT people fetch failed', [
                        'event_id' => $event->id,
                        'draht_id' => $drahtId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $merged;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchHelpers(Event $event, string $q): array
    {
        $rows = $this->staffedHelpersGrouped($event->id);

        $hits = [];
        foreach ($rows as $row) {
            $roleLabels = $row->role_labels ? explode('||', $row->role_labels) : [];
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row->first_name,
                $row->last_name,
                $row->email,
                $row->mobile,
                $row->organization,
                ...$roleLabels,
            ])));

            if (! str_contains($haystack, $q)) {
                continue;
            }

            $hits[] = [
                'id' => (int) $row->id,
                'label' => trim($row->first_name.' '.$row->last_name),
                'subtitle' => $roleLabels[0] ?? $row->organization,
                'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
                'program_name' => $row->program_name,
                'logo_stem' => $row->logo_stem ?: null,
                'role_labels' => $roleLabels,
            ];
        }

        return $hits;
    }

    private function staffedHelpersQuery(int $eventId)
    {
        return DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->join('volunteer_person as p', 'p.id', '=', 'a.volunteer_person')
            ->leftJoin('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'mr.first_program')
            ->where('r.event', $eventId)
            ->select([
                'p.id',
                'p.first_name',
                'p.last_name',
                'p.email',
                'p.mobile',
                'p.organization',
                'r.m_role',
                'r.label as role_label',
                'mr.name as catalog_role_name',
                'mr.first_program',
                'fp.name as program_name',
                'fp.logo_stem',
                'a.id as assignment_id',
            ])
            ->orderBy('p.last_name')
            ->orderBy('p.first_name')
            ->orderBy('a.id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function staffedHelpersGrouped(int $eventId)
    {
        $rows = $this->staffedHelpersQuery($eventId)->get();
        $byPerson = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! isset($byPerson[$id])) {
                $byPerson[$id] = (object) [
                    'id' => $id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'mobile' => $row->mobile,
                    'organization' => $row->organization,
                    'first_program' => null,
                    'program_name' => null,
                    'logo_stem' => null,
                    'is_local' => true,
                    'role_labels' => [],
                    'scope_picked' => false,
                ];
            }
            $label = $row->role_label ?: $row->catalog_role_name;
            if ($label && ! in_array($label, $byPerson[$id]->role_labels, true)) {
                $byPerson[$id]->role_labels[] = $label;
            }

            // Same preference as Zuordnung / check-in role pick: catalog (regular) before local.
            $isLocal = $row->m_role === null;
            if (! $byPerson[$id]->scope_picked || ($byPerson[$id]->is_local && ! $isLocal)) {
                $byPerson[$id]->is_local = $isLocal;
                $byPerson[$id]->first_program = $row->first_program !== null ? (int) $row->first_program : null;
                $byPerson[$id]->program_name = $row->program_name;
                $byPerson[$id]->logo_stem = $row->logo_stem ?: null;
                $byPerson[$id]->scope_picked = true;
            }
        }

        return collect(array_values($byPerson))->map(function ($person) {
            $person->role_labels = implode('||', $person->role_labels);
            unset($person->scope_picked);

            return $person;
        });
    }

    public function detail(Event $event, string $subjectType, int $subjectId): array
    {
        if ($subjectType === CheckIn::SUBJECT_TEAM) {
            return $this->teamDetail($event, $subjectId);
        }
        if ($subjectType === CheckIn::SUBJECT_VOLUNTEER) {
            return $this->helperDetail($event, $subjectId);
        }

        throw new \InvalidArgumentException('Unknown subject type.');
    }

    private function teamDetail(Event $event, int $teamId): array
    {
        $plan = Plan::query()->where('event', $event->id)->orderBy('id')->first();
        $row = DB::table('team')
            ->leftJoin('team_plan', function ($join) use ($plan) {
                $join->on('team_plan.team', '=', 'team.id');
                if ($plan) {
                    $join->where('team_plan.plan', '=', $plan->id);
                }
            })
            ->leftJoin('room', 'room.id', '=', 'team_plan.room')
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'team.first_program')
            ->where('team.event', $event->id)
            ->where('team.id', $teamId)
            ->select([
                'team.id',
                'team.name',
                'team.team_number_hot',
                'team.first_program',
                'team_plan.team_number_plan',
                'team_plan.room as room_id',
                'room.name as room_name',
                'fp.name as program_name',
                'fp.logo_stem',
            ])
            ->first();

        if (! $row) {
            abort(404, 'Team not found');
        }

        $record = $this->findRecord($event, CheckIn::SUBJECT_TEAM, $teamId);
        $label = trim((string) ($row->name ?? ''));

        return array_merge([
            'subject_type' => CheckIn::SUBJECT_TEAM,
            'subject_id' => (int) $row->id,
            'label' => $label !== '' ? $label : ('Team '.$row->id),
            'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
            'program_name' => $row->program_name,
            'logo_stem' => $row->logo_stem ?: null,
            'room' => $row->room_name ?: null,
            'info_text' => $event->check_in_text_teams,
            'next_activities' => $this->nextActivitiesForTeam($event, $plan, $row),
        ], $this->recordStatusPayload($record));
    }

    private function helperDetail(Event $event, int $personId): array
    {
        $row = $this->staffedHelpersGrouped($event->id)->firstWhere('id', $personId);

        if (! $row) {
            abort(404, 'Helper not found or not staffed');
        }

        $record = $this->findRecord($event, CheckIn::SUBJECT_VOLUNTEER, $personId);
        $roleLabels = $row->role_labels ? explode('||', $row->role_labels) : [];

        return array_merge([
            'subject_type' => CheckIn::SUBJECT_VOLUNTEER,
            'subject_id' => (int) $row->id,
            'label' => trim($row->first_name.' '.$row->last_name),
            'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
            'program_name' => $row->program_name,
            'logo_stem' => $row->logo_stem ?: null,
            'room' => null,
            'role_labels' => $roleLabels,
            'info_text' => $event->check_in_text_helpers,
            'next_activities' => $this->nextActivitiesForHelper($event, $personId),
        ], $this->recordStatusPayload($record));
    }

    /**
     * @return list<array{start: ?string, room: ?string, title: string}>
     */
    private function nextActivitiesForTeam(Event $event, ?Plan $plan, object $teamRow): array
    {
        if (! $plan || $teamRow->team_number_plan === null) {
            return [];
        }

        $roleId = DB::table('m_role')
            ->where('differentiation_parameter', 'team')
            ->when($teamRow->first_program !== null, fn ($q) => $q->where('first_program', $teamRow->first_program))
            ->orderBy('id')
            ->value('id');

        if (! $roleId) {
            return [];
        }

        return $this->nextTwoFromSchedule((int) $plan->id, [
            'role' => (int) $roleId,
            'team' => (int) $teamRow->team_number_plan,
            'expired' => 'no',
        ]);
    }

    /**
     * @return list<array{start: ?string, room: ?string, title: string}>
     */
    private function nextActivitiesForHelper(Event $event, int $personId): array
    {
        $plan = Plan::query()->where('event', $event->id)->orderBy('id')->first();
        if (! $plan) {
            return [];
        }

        $assignments = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $event->id)
            ->where('a.volunteer_person', $personId)
            ->orderByRaw('CASE WHEN r.m_role IS NULL THEN 1 ELSE 0 END')
            ->orderBy('a.id')
            ->select(['r.m_role', 'r.label', 'a.id as assignment_id'])
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $regular = $assignments->first(fn ($row) => $row->m_role !== null);
        $chosen = $regular ?? $assignments->first();
        if (! $chosen || $chosen->m_role === null) {
            return [];
        }

        return $this->nextTwoFromSchedule((int) $plan->id, [
            'role' => (int) $chosen->m_role,
            'expired' => 'no',
        ]);
    }

    /**
     * @return list<array{start: ?string, room: ?string, title: string}>
     */
    private function nextTwoFromSchedule(int $planId, array $query): array
    {
        try {
            $schedule = $this->publicPlan->getSchedule($planId, $query);
        } catch (\Throwable $e) {
            Log::warning('Check-in next activities failed', ['error' => $e->getMessage()]);

            return [];
        }

        $groups = collect($schedule['groups'] ?? [])
            ->filter(fn ($g) => ! empty($g['start_time']))
            ->sortBy('start_time')
            ->values();

        $out = [];
        foreach ($groups->take(2) as $group) {
            $title = $group['group_meta']['name'] ?? 'Aktivität';
            $room = null;
            if (! empty($group['activities']) && is_array($group['activities'])) {
                $first = $group['activities'][0] ?? null;
                if (is_array($first)) {
                    $title = $first['activity_name']
                        ?? $first['meta']['name']
                        ?? $title;
                    $roomInfo = $first['room'] ?? null;
                    if (is_array($roomInfo)) {
                        $room = $roomInfo['room_name'] ?: ($roomInfo['room_type_name'] ?? null);
                    }
                }
            }
            $out[] = [
                'start' => isset($group['start_time']) ? Carbon::parse($group['start_time'])->format('H:i') : null,
                'room' => $room ? (string) $room : null,
                'title' => (string) $title,
            ];
        }

        return $out;
    }

    public function checkIn(Event $event, string $subjectType, int $subjectId, ?string $note): CheckIn
    {
        $this->assertSubjectExists($event, $subjectType, $subjectId);

        $record = $this->findRecord($event, $subjectType, $subjectId);
        if ($record?->isNoShow()) {
            throw new \RuntimeException('NO_SHOW_BLOCKED');
        }

        if (! $record) {
            $record = new CheckIn([
                'event' => $event->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);
        }

        $record->status = CheckIn::STATUS_CHECKED_IN;
        $record->checked_in_at = now();
        $record->reception_note = $note;
        $record->no_show_reason = null;
        $record->no_show_source = null;
        $record->save();

        return $record;
    }

    public function markNoShow(
        Event $event,
        string $subjectType,
        int $subjectId,
        string $reason,
        string $source,
        string $note,
    ): CheckIn {
        $this->assertSubjectExists($event, $subjectType, $subjectId);

        $record = $this->findRecord($event, $subjectType, $subjectId) ?? new CheckIn([
            'event' => $event->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);

        $record->status = CheckIn::STATUS_NO_SHOW;
        $record->checked_in_at = null;
        $record->no_show_reason = $reason;
        $record->no_show_source = $source;
        $record->reception_note = $note;
        $record->save();

        return $record;
    }

    public function updateNote(Event $event, string $subjectType, int $subjectId, ?string $note): CheckIn
    {
        $this->assertSubjectExists($event, $subjectType, $subjectId);

        $record = $this->findRecord($event, $subjectType, $subjectId);
        if (! $record) {
            throw new \RuntimeException('NO_RECORD');
        }

        $record->reception_note = $note === '' ? null : $note;
        $record->save();

        return $record;
    }

    private function assertSubjectExists(Event $event, string $subjectType, int $subjectId): void
    {
        if ($subjectType === CheckIn::SUBJECT_TEAM) {
            $ok = DB::table('team')->where('event', $event->id)->where('id', $subjectId)->exists();
            if (! $ok) {
                abort(404, 'Team not found');
            }

            return;
        }

        if ($subjectType === CheckIn::SUBJECT_VOLUNTEER) {
            $ok = $this->staffedHelpersGrouped($event->id)->contains(fn ($row) => (int) $row->id === $subjectId);
            if (! $ok) {
                abort(404, 'Helper not found or not staffed');
            }

            return;
        }

        abort(422, 'Unknown subject type');
    }

    public function overview(Event $event): array
    {
        $plan = Plan::query()->where('event', $event->id)->orderBy('id')->first();

        $teams = DB::table('team')
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'team.first_program')
            ->where('team.event', $event->id)
            ->select('team.id', 'team.first_program', 'fp.name as program_name', 'fp.logo_stem')
            ->get();

        $teamRecords = CheckIn::query()
            ->where('event', $event->id)
            ->where('subject_type', CheckIn::SUBJECT_TEAM)
            ->where('status', CheckIn::STATUS_CHECKED_IN)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $helpers = $this->staffedHelpersGrouped($event->id);
        $helperRecords = CheckIn::query()
            ->where('event', $event->id)
            ->where('subject_type', CheckIn::SUBJECT_VOLUNTEER)
            ->where('status', CheckIn::STATUS_CHECKED_IN)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $teamLines = $this->teamOverviewLines($teams, $teamRecords);
        $helperLines = $this->helperOverviewLines($helpers, $helperRecords);

        return [
            'teams' => $teamLines,
            'helpers' => $helperLines,
            'totals' => [
                'teams_checked_in' => $teamLines[0]['checked_in'] ?? 0,
                'teams_total' => $teamLines[0]['total'] ?? 0,
                'helpers_checked_in' => $helperLines[0]['checked_in'] ?? 0,
                'helpers_total' => $helperLines[0]['total'] ?? 0,
            ],
            'plan_id' => $plan?->id,
        ];
    }

    /**
     * Gesamt + program logos (+ Übergreifend only when mixed with programs).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $teams
     * @param  list<int>  $checkedIds
     * @return list<array{kind: string, program_id: ?int, program_name: string, logo_stem: ?string, checked_in: int, total: int}>
     */
    private function teamOverviewLines(Collection $teams, array $checkedIds): array
    {
        $allIds = $teams->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lines = [[
            'kind' => 'global',
            'program_id' => null,
            'program_name' => 'Gesamt',
            'logo_stem' => null,
            'checked_in' => count(array_intersect($allIds, $checkedIds)),
            'total' => count($allIds),
        ]];

        $cross = null;
        foreach ($teams->groupBy(fn ($row) => $row->first_program ?? 0) as $programId => $group) {
            $ids = $group->pluck('id')->map(fn ($id) => (int) $id)->all();
            $payload = [
                'program_id' => $programId ? (int) $programId : null,
                'program_name' => $group->first()->program_name ?? 'Teams',
                'logo_stem' => $group->first()->logo_stem ?: null,
                'checked_in' => count(array_intersect($ids, $checkedIds)),
                'total' => count($ids),
            ];

            if (! $programId) {
                $cross = array_merge(['kind' => 'cross'], $payload, ['program_name' => 'Übergreifend']);
                continue;
            }

            $lines[] = array_merge(['kind' => 'program'], $payload);
        }

        if ($cross && $cross['total'] > 0 && count($lines) > 1) {
            // Match Zuordnung filter order: Übergreifend before programs.
            array_splice($lines, 1, 0, [$cross]);
        }

        return $lines;
    }

    /**
     * Same scopes as Helfer:innen → Zuordnung: Gesamt, Übergreifend, programs, Zusätzlich.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $helpers
     * @param  list<int>  $checkedIds
     * @return list<array{kind: string, program_id: ?int, program_name: string, logo_stem: ?string, checked_in: int, total: int}>
     */
    private function helperOverviewLines(Collection $helpers, array $checkedIds): array
    {
        $allIds = $helpers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lines = [[
            'kind' => 'global',
            'program_id' => null,
            'program_name' => 'Gesamt',
            'logo_stem' => null,
            'checked_in' => count(array_intersect($allIds, $checkedIds)),
            'total' => count($allIds),
        ]];

        $crossIds = [];
        $localIds = [];
        $byProgram = [];

        foreach ($helpers as $helper) {
            $id = (int) $helper->id;
            if (! empty($helper->is_local)) {
                $localIds[] = $id;
                continue;
            }
            if ($helper->first_program === null) {
                $crossIds[] = $id;
                continue;
            }
            $pid = (int) $helper->first_program;
            if (! isset($byProgram[$pid])) {
                $byProgram[$pid] = [
                    'ids' => [],
                    'program_name' => $helper->program_name ?? 'Programm',
                    'logo_stem' => $helper->logo_stem ?: null,
                ];
            }
            $byProgram[$pid]['ids'][] = $id;
            if (! $byProgram[$pid]['logo_stem'] && $helper->logo_stem) {
                $byProgram[$pid]['logo_stem'] = $helper->logo_stem;
            }
        }

        if ($crossIds !== []) {
            $lines[] = [
                'kind' => 'cross',
                'program_id' => null,
                'program_name' => 'Übergreifend',
                'logo_stem' => null,
                'checked_in' => count(array_intersect($crossIds, $checkedIds)),
                'total' => count($crossIds),
            ];
        }

        ksort($byProgram);
        foreach ($byProgram as $programId => $bucket) {
            $lines[] = [
                'kind' => 'program',
                'program_id' => $programId,
                'program_name' => $bucket['program_name'],
                'logo_stem' => $bucket['logo_stem'],
                'checked_in' => count(array_intersect($bucket['ids'], $checkedIds)),
                'total' => count($bucket['ids']),
            ];
        }

        if ($localIds !== []) {
            $lines[] = [
                'kind' => 'local',
                'program_id' => null,
                'program_name' => 'Zusätzlich',
                'logo_stem' => null,
                'checked_in' => count(array_intersect($localIds, $checkedIds)),
                'total' => count($localIds),
            ];
        }

        return $lines;
    }

    public function organizerContact(Event $event): ?array
    {
        $row = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->join('volunteer_person as p', 'p.id', '=', 'a.volunteer_person')
            ->where('r.event', $event->id)
            ->where('r.m_role', self::ORGANIZER_M_ROLE)
            ->orderBy('a.id')
            ->select(['p.id', 'p.first_name', 'p.last_name', 'p.mobile'])
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => trim($row->first_name.' '.$row->last_name),
            'mobile' => $row->mobile ?: null,
        ];
    }

    public function shareText(Event $event): string
    {
        $overview = $this->overview($event);
        $lines = ['Check-In: '.$event->name, ''];

        $t = $overview['totals'];
        $lines[] = sprintf('Teams: %d/%d', $t['teams_checked_in'], $t['teams_total']);
        foreach ($overview['teams'] as $row) {
            if (($row['kind'] ?? '') === 'global') {
                continue;
            }
            $lines[] = sprintf('  %s: %d/%d', $row['program_name'], $row['checked_in'], $row['total']);
        }
        $lines[] = sprintf('Helfer:innen: %d/%d', $t['helpers_checked_in'], $t['helpers_total']);
        foreach ($overview['helpers'] as $row) {
            if (($row['kind'] ?? '') === 'global') {
                continue;
            }
            $lines[] = sprintf('  %s: %d/%d', $row['program_name'], $row['checked_in'], $row['total']);
        }

        $records = CheckIn::query()
            ->where('event', $event->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($records->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Einträge:';
            foreach ($records as $record) {
                $label = $this->subjectLabel($event, $record);
                $status = $record->status === CheckIn::STATUS_NO_SHOW ? 'No-Show' : 'Check-In';
                $lines[] = sprintf('- %s [%s]', $label, $status);
                if ($record->reception_note) {
                    $lines[] = '  Notiz: '.$record->reception_note;
                }
                if ($record->isNoShow()) {
                    if ($record->no_show_reason) {
                        $lines[] = '  Grund: '.$record->no_show_reason;
                    }
                    if ($record->no_show_source) {
                        $lines[] = '  Quelle: '.$record->no_show_source;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function subjectLabel(Event $event, CheckIn $record): string
    {
        try {
            $detail = $this->detail($event, $record->subject_type, (int) $record->subject_id);

            return $detail['label'] ?? ($record->subject_type.' #'.$record->subject_id);
        } catch (\Throwable) {
            return $record->subject_type.' #'.$record->subject_id;
        }
    }
}
