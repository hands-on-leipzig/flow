<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Models\EventVolunteerField;
use App\Models\EventVolunteerFieldValue;
use App\Models\EventVolunteerRoster;
use App\Models\Plan;
use App\Support\PhotoConsentStatus;
use App\Support\TeamDataCustomFields;
use App\Support\TeamMealCounts;
use App\Support\TeamPeopleCounts;
use App\Support\TeamPhotoCounts;
use App\Support\VolunteerCollectOptions;
use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterCustomFields;
use App\Support\VolunteerRosterDetailFields;
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
            'show_fields' => [
                'teams' => [
                    'photo_consent' => (bool) ($event->check_in_show_team_photo ?? true),
                    'meal' => (bool) ($event->check_in_show_team_meal ?? false),
                ],
                'helpers' => [
                    'photo_consent' => (bool) ($event->check_in_show_helper_photo ?? true),
                    'meal' => (bool) ($event->check_in_show_helper_meal ?? false),
                    't_shirt' => (bool) ($event->check_in_show_helper_t_shirt ?? false),
                ],
            ],
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
                'fp.display_name as program_display_name',
                'fp.sequence as program_sequence',
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
                    'program_display_name' => null,
                    'program_sequence' => null,
                    'logo_stem' => null,
                    'is_local' => true,
                    'role_label' => null,
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
                $byPerson[$id]->program_display_name = $row->program_display_name ?: $row->program_name;
                $byPerson[$id]->program_sequence = $row->program_sequence !== null ? (int) $row->program_sequence : null;
                $byPerson[$id]->logo_stem = $row->logo_stem ?: null;
                $byPerson[$id]->role_label = $label ?: null;
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
        $peopleBreakdown = TeamPeopleCounts::breakdownForTeam($event, $teamId);
        $photoPayload = PhotoConsentStatus::forTeam(
            TeamPhotoCounts::mapForTeamWithDefaults($teamId),
            $peopleBreakdown['total'] ?? null,
        );

        return array_merge([
            'subject_type' => CheckIn::SUBJECT_TEAM,
            'subject_id' => (int) $row->id,
            'label' => $label !== '' ? $label : ('Team '.$row->id),
            'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
            'program_name' => $row->program_name,
            'logo_stem' => $row->logo_stem ?: null,
            'room' => $row->room_name ?: null,
            'coaches_count' => $peopleBreakdown['coaches'] ?? null,
            'players_count' => $peopleBreakdown['players'] ?? null,
            'people_count' => $peopleBreakdown['total'] ?? null,
            'info_text' => $event->check_in_text_teams,
            'next_activities' => $this->nextActivitiesForTeam($event, $plan, $row),
            'display_fields' => $this->teamDisplayFields($event, $teamId, $photoPayload),
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

        $roster = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $personId)
            ->with('detail')
            ->first();
        $consent = $roster?->detail?->photo_consent;
        $photoPayload = PhotoConsentStatus::forVolunteer(
            $consent === null ? null : (bool) $consent,
        );

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
            'display_fields' => $this->helperDisplayFields($event, $roster, $photoPayload),
        ], $this->recordStatusPayload($record));
    }

    /**
     * @param  array{status: string, check_in_label: string}  $photoPayload
     * @return list<array{key: string, kind: string, label: string, value: string, status?: string}>
     */
    private function teamDisplayFields(Event $event, int $teamId, array $photoPayload): array
    {
        $fields = [];

        $fields[] = [
            'key' => 'photo_consent',
            'kind' => 'photo_consent',
            'label' => 'Fotoerlaubnis',
            'value' => $photoPayload['check_in_label'],
            'status' => $photoPayload['status'],
        ];

        if ((bool) ($event->check_in_show_team_meal ?? false) && VolunteerCollectOptions::collectsMeal($event)) {
            $fields[] = [
                'key' => 'meal',
                'kind' => 'text',
                'label' => 'Essen',
                'value' => $this->formatTeamMealValue($event->id, $teamId),
            ];
        }

        $customFields = EventTeamField::query()
            ->where('event', $event->id)
            ->where('check_in_show', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($customFields->isNotEmpty()) {
            $valuesByFieldId = EventTeamFieldValue::query()
                ->where('team', $teamId)
                ->whereIn('event_team_field', $customFields->pluck('id'))
                ->get()
                ->keyBy('event_team_field');

            foreach ($customFields as $field) {
                $stored = $valuesByFieldId->get($field->id)?->value;
                $exported = TeamDataCustomFields::exportValue($field, $stored);
                $fields[] = [
                    'key' => $field->field_key,
                    'kind' => 'text',
                    'label' => $field->label,
                    'value' => $exported !== '' ? $exported : '—',
                ];
            }
        }

        return $fields;
    }

    /**
     * @param  array{status: string, check_in_label: string}  $photoPayload
     * @return list<array{key: string, kind: string, label: string, value: string, status?: string}>
     */
    private function helperDisplayFields(Event $event, ?EventVolunteerRoster $roster, array $photoPayload): array
    {
        $fields = [];
        $collect = VolunteerCollectOptions::forEvent($event);
        $detail = $roster?->detail;

        $fields[] = [
            'key' => 'photo_consent',
            'kind' => 'photo_consent',
            'label' => 'Fotoerlaubnis',
            'value' => $photoPayload['check_in_label'],
            'status' => $photoPayload['status'],
        ];

        if ((bool) ($event->check_in_show_helper_t_shirt ?? false) && ($collect['t_shirt'] ?? false)) {
            $cut = VolunteerRosterDetailFields::exportLabel($detail?->t_shirt_cut);
            $size = trim((string) ($detail?->t_shirt_size ?? ''));
            $shirt = trim($cut.' '.$size);
            $fields[] = [
                'key' => 't_shirt',
                'kind' => 'text',
                'label' => 'T-Shirt Größe',
                'value' => $shirt !== '' ? $shirt : '—',
            ];
        }

        if ((bool) ($event->check_in_show_helper_meal ?? false) && ($collect['meal'] ?? false)) {
            $options = VolunteerMealOptions::optionsForEvent($event->id);
            $labelMap = VolunteerMealOptions::labelMap($options);
            $mealLabel = VolunteerRosterDetailFields::exportMealLabel($detail?->meal, $labelMap);
            $fields[] = [
                'key' => 'meal',
                'kind' => 'text',
                'label' => 'Essen',
                'value' => $mealLabel !== '' ? $mealLabel : '—',
            ];
        }

        $customFields = EventVolunteerField::query()
            ->where('event', $event->id)
            ->where('check_in_show', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($customFields->isNotEmpty() && $roster) {
            $valuesByFieldId = EventVolunteerFieldValue::query()
                ->where('event_volunteer_roster', $roster->id)
                ->whereIn('event_volunteer_field', $customFields->pluck('id'))
                ->get()
                ->keyBy('event_volunteer_field');

            foreach ($customFields as $field) {
                $stored = $valuesByFieldId->get($field->id)?->value;
                $exported = VolunteerRosterCustomFields::exportValue($field, $stored);
                $fields[] = [
                    'key' => $field->field_key,
                    'kind' => 'text',
                    'label' => $field->label,
                    'value' => $exported !== '' ? $exported : '—',
                ];
            }
        } elseif ($customFields->isNotEmpty()) {
            foreach ($customFields as $field) {
                $fields[] = [
                    'key' => $field->field_key,
                    'kind' => 'text',
                    'label' => $field->label,
                    'value' => '—',
                ];
            }
        }

        return $fields;
    }

    private function formatTeamMealValue(int $eventId, int $teamId): string
    {
        $options = VolunteerMealOptions::optionsForEvent($eventId);
        if ($options->isEmpty()) {
            return '—';
        }

        $counts = TeamMealCounts::mapForTeamWithCatalog($teamId, $eventId);
        $parts = [];
        foreach ($options as $option) {
            $count = (int) ($counts[$option->value] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $parts[] = $count.'× '.$option->label;
        }

        return $parts !== [] ? implode(', ', $parts) : '—';
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
        ?string $note,
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
        $record->reception_note = $note === '' || $note === null ? null : $note;
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
     * Open + no-show lists for Teams or Helfer:innen (checked-in omitted).
     * Status sections first; within each, same program/scope order as overview.
     *
     * @return array{scope: string, title: string, sections: list<array{key: string, label: string, groups: list<array{kind: string, program_id: ?int, logo_stem: ?string, items: list<array<string, mixed>>}>}>}
     */
    public function roster(Event $event, string $scope): array
    {
        if (! in_array($scope, ['teams', 'helpers'], true)) {
            abort(422, 'Unknown roster scope');
        }

        $records = CheckIn::query()
            ->where('event', $event->id)
            ->where('subject_type', $scope === 'teams' ? CheckIn::SUBJECT_TEAM : CheckIn::SUBJECT_VOLUNTEER)
            ->get()
            ->keyBy(fn (CheckIn $row) => (int) $row->subject_id);

        if ($scope === 'teams') {
            $items = $this->teamRosterItems($event, $records);
            $title = 'Teams';
        } else {
            $items = $this->helperRosterItems($event, $records);
            $title = 'Helfer:innen';
        }

        $open = array_values(array_filter($items, fn (array $hit) => ($hit['status'] ?? null) === null));
        $noShow = array_values(array_filter($items, fn (array $hit) => ($hit['status'] ?? null) === CheckIn::STATUS_NO_SHOW));

        return [
            'scope' => $scope,
            'title' => $title,
            'sections' => [
                [
                    'key' => 'open',
                    'label' => 'Noch offen',
                    'groups' => $this->rosterGroups($open, $scope),
                ],
                [
                    'key' => 'no_show',
                    'label' => 'No-Show',
                    'groups' => $this->rosterGroups($noShow, $scope),
                ],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CheckIn>  $records
     * @return list<array<string, mixed>>
     */
    private function teamRosterItems(Event $event, Collection $records): array
    {
        $rows = DB::table('team')
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'team.first_program')
            ->where('team.event', $event->id)
            ->select([
                'team.id',
                'team.name',
                'team.first_program',
                'fp.name as program_name',
                'fp.logo_stem',
                'fp.sequence as program_sequence',
            ])
            ->orderByRaw('CASE WHEN team.first_program IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fp.sequence')
            ->orderBy('team.name')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $record = $records->get($id);
            if ($record?->isCheckedIn()) {
                continue;
            }

            $label = trim((string) ($row->name ?? ''));
            $items[] = array_merge([
                'subject_type' => CheckIn::SUBJECT_TEAM,
                'subject_id' => $id,
                'label' => $label !== '' ? $label : ('Team '.$id),
                'subtitle' => 'Team',
                'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
                'program_name' => $row->program_name,
                'program_sequence' => $row->program_sequence !== null ? (int) $row->program_sequence : null,
                'logo_stem' => $row->logo_stem ?: null,
                'scope_kind' => $row->first_program === null ? 'cross' : 'program',
            ], $this->recordStatusPayload($record));
        }

        return $items;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CheckIn>  $records
     * @return list<array<string, mixed>>
     */
    private function helperRosterItems(Event $event, Collection $records): array
    {
        $items = [];
        foreach ($this->staffedHelpersGrouped($event->id) as $row) {
            $id = (int) $row->id;
            $record = $records->get($id);
            if ($record?->isCheckedIn()) {
                continue;
            }

            $roleLabels = $row->role_labels ? explode('||', $row->role_labels) : [];
            if (! empty($row->is_local)) {
                $scopeKind = 'local';
            } elseif ($row->first_program === null) {
                $scopeKind = 'cross';
            } else {
                $scopeKind = 'program';
            }

            $items[] = array_merge([
                'subject_type' => CheckIn::SUBJECT_VOLUNTEER,
                'subject_id' => $id,
                'label' => trim($row->first_name.' '.$row->last_name),
                'subtitle' => $roleLabels[0] ?? $row->organization,
                'program_id' => $row->first_program !== null ? (int) $row->first_program : null,
                'program_name' => $row->program_name,
                'program_sequence' => $row->program_sequence !== null ? (int) $row->program_sequence : null,
                'logo_stem' => $row->logo_stem ?: null,
                'scope_kind' => $scopeKind,
                'role_labels' => $roleLabels,
            ], $this->recordStatusPayload($record));
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{kind: string, program_id: ?int, logo_stem: ?string, items: list<array<string, mixed>>}>
     */
    private function rosterGroups(array $items, string $scope): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $kind = (string) ($item['scope_kind'] ?? 'program');
            $programId = $item['program_id'] ?? null;
            $key = $kind === 'program' ? 'program:'.(int) $programId : $kind;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'kind' => $kind,
                    'program_id' => $kind === 'program' ? ($programId !== null ? (int) $programId : null) : null,
                    'program_sequence' => $item['program_sequence'] ?? null,
                    'logo_stem' => $item['logo_stem'] ?? null,
                    'items' => [],
                ];
            }
            if (! $buckets[$key]['logo_stem'] && ! empty($item['logo_stem'])) {
                $buckets[$key]['logo_stem'] = $item['logo_stem'];
            }
            $clean = $item;
            unset($clean['scope_kind'], $clean['program_sequence']);
            $buckets[$key]['items'][] = $clean;
        }

        foreach ($buckets as &$bucket) {
            usort($bucket['items'], fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        }
        unset($bucket);

        $orderedKeys = [];
        if (isset($buckets['cross'])) {
            $orderedKeys[] = 'cross';
        }

        $programKeys = array_keys(array_filter(
            $buckets,
            fn ($b, $k) => str_starts_with((string) $k, 'program:'),
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($programKeys, function (string $a, string $b) use ($buckets) {
            $sa = $buckets[$a]['program_sequence'] ?? PHP_INT_MAX;
            $sb = $buckets[$b]['program_sequence'] ?? PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return ($buckets[$a]['program_id'] ?? 0) <=> ($buckets[$b]['program_id'] ?? 0);
        });
        foreach ($programKeys as $key) {
            $orderedKeys[] = $key;
        }

        if ($scope === 'helpers' && isset($buckets['local'])) {
            $orderedKeys[] = 'local';
        }

        foreach (array_keys($buckets) as $key) {
            if (! in_array($key, $orderedKeys, true)) {
                $orderedKeys[] = $key;
            }
        }

        $ordered = [];
        foreach ($orderedKeys as $key) {
            $bucket = $buckets[$key];
            unset($bucket['program_sequence']);
            $ordered[] = $bucket;
        }

        return $ordered;
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
        $records = CheckIn::query()
            ->where('event', $event->id)
            ->get()
            ->keyBy(fn (CheckIn $row) => $row->subject_type.':'.$row->subject_id);

        $lines = ['Check-In: '.$event->name, ''];

        $teams = DB::table('team')
            ->leftJoin('m_first_program as fp', 'fp.id', '=', 'team.first_program')
            ->where('team.event', $event->id)
            ->select([
                'team.id',
                'team.name',
                'team.first_program',
                'fp.name as program_name',
                'fp.display_name as program_display_name',
                'fp.sequence as program_sequence',
            ])
            ->get();

        $teamGroups = $teams->groupBy(fn ($t) => $t->first_program ?? 0);
        $teamKeys = $teamGroups->keys()->sort(function ($a, $b) use ($teamGroups) {
            $a = (int) $a;
            $b = (int) $b;
            if ($a === 0) {
                return -1;
            }
            if ($b === 0) {
                return 1;
            }
            $seqA = (int) ($teamGroups[$a]->first()->program_sequence ?? PHP_INT_MAX);
            $seqB = (int) ($teamGroups[$b]->first()->program_sequence ?? PHP_INT_MAX);

            return $seqA <=> $seqB ?: $a <=> $b;
        })->values();

        foreach ($teamKeys as $programKey) {
            $group = $teamGroups[$programKey];
            $subtitle = ((int) $programKey) === 0
                ? 'Übergreifend'
                : (string) ($group->first()->program_display_name
                    ?: $group->first()->program_name
                    ?: 'Programm');
            $lines[] = $subtitle;
            foreach ($group->sortBy(fn ($t) => mb_strtolower(trim((string) ($t->name ?? ''))), SORT_NATURAL)->values() as $team) {
                $name = trim((string) ($team->name ?? ''));
                $record = $records->get(CheckIn::SUBJECT_TEAM.':'.(int) $team->id);
                $lines[] = $this->tsvExportLine($name !== '' ? $name : ('Team '.$team->id), $record);
            }
            $lines[] = '';
        }

        $lines[] = 'Helfer:innen';

        $helpers = $this->staffedHelpersGrouped($event->id);
        $helperBuckets = [];
        foreach ($helpers as $helper) {
            if (! empty($helper->is_local)) {
                $scope = 'local';
                $scopeLabel = 'Zusätzlich';
                $sequence = PHP_INT_MAX;
            } elseif ($helper->first_program === null) {
                $scope = 'cross';
                $scopeLabel = 'Übergreifend';
                $sequence = -1;
            } else {
                $scope = 'program:'.(int) $helper->first_program;
                $scopeLabel = (string) ($helper->program_display_name
                    ?: $helper->program_name
                    ?: 'Programm');
                $sequence = $helper->program_sequence !== null
                    ? (int) $helper->program_sequence
                    : PHP_INT_MAX - 1;
            }
            $role = trim((string) ($helper->role_label ?? ''));
            if ($role === '') {
                $role = 'Rolle';
            }
            $bucketKey = $scope.'|'.$role;
            if (! isset($helperBuckets[$bucketKey])) {
                $helperBuckets[$bucketKey] = [
                    'scope' => $scope,
                    'sequence' => $sequence,
                    'scope_label' => $scopeLabel,
                    'role' => $role,
                    'people' => [],
                ];
            }
            $helperBuckets[$bucketKey]['people'][] = $helper;
        }

        uasort($helperBuckets, function (array $a, array $b) {
            if ($a['sequence'] !== $b['sequence']) {
                return $a['sequence'] <=> $b['sequence'];
            }
            $roleCmp = strcasecmp($a['role'], $b['role']);
            if ($roleCmp !== 0) {
                return $roleCmp;
            }

            return strcasecmp($a['scope_label'], $b['scope_label']);
        });

        foreach ($helperBuckets as $bucket) {
            $lines[] = $bucket['scope_label'].' — '.$bucket['role'];
            usort($bucket['people'], function ($a, $b) {
                $last = strcasecmp((string) $a->last_name, (string) $b->last_name);
                if ($last !== 0) {
                    return $last;
                }

                return strcasecmp((string) $a->first_name, (string) $b->first_name);
            });
            foreach ($bucket['people'] as $person) {
                $name = trim($person->first_name.' '.$person->last_name);
                $record = $records->get(CheckIn::SUBJECT_VOLUNTEER.':'.(int) $person->id);
                $lines[] = $this->tsvExportLine($name !== '' ? $name : ('Helfer:innen '.$person->id), $record);
            }
            $lines[] = '';
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    private function tsvExportLine(string $name, ?CheckIn $record): string
    {
        return $name."\t".$this->exportStatus($record)."\t".$this->exportExtra($record);
    }

    private function exportStatus(?CheckIn $record): string
    {
        if (! $record) {
            return 'Offen';
        }
        if ($record->isNoShow()) {
            return 'No-Show';
        }
        if ($record->isCheckedIn()) {
            return 'Da';
        }

        return 'Offen';
    }

    private function exportExtra(?CheckIn $record): string
    {
        if (! $record) {
            return '';
        }

        $parts = [];
        if ($record->reception_note) {
            $parts[] = $record->reception_note;
        }
        if ($record->isNoShow()) {
            if ($record->no_show_reason) {
                $parts[] = 'Grund: '.$record->no_show_reason;
            }
            if ($record->no_show_source) {
                $parts[] = 'Quelle: '.$record->no_show_source;
            }
        }

        return implode(' | ', $parts);
    }
}
