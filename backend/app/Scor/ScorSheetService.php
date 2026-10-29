<?php

namespace App\Scor;

use App\Enums\FirstProgram;
use App\Models\User;
use App\Services\TeamJuryAssignmentService;
use App\Support\FlowAccess;
use App\Support\ScorGrant;
use App\Support\TableFieldLabels;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ScorSheetService
{
    public function __construct(private ScorFormStore $forms) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsFor(User $user): array
    {
        $grantAll = $user->isFlowAdmin();
        $eventIds = $grantAll
            ? $this->futureEventIds(null)
            : $this->futureEventIds($user->accessibleEventIds(FlowAccess::PERMISSION_SCOR));

        if ($eventIds === []) {
            return [];
        }

        return DB::table('event')
            ->whereIn('id', $eventIds)
            ->orderBy('date')
            ->orderBy('name')
            ->get(['id', 'name', 'date', 'season'])
            ->map(fn ($event) => [
                'id' => (int) $event->id,
                'name' => $event->name,
                'date' => $event->date,
                'roles' => $this->roles($user, (int) $event->id)->columns(),
            ])
            ->all();
    }

    public function overview(User $user, int $eventId): array
    {
        $roles = $this->requireEvent($user, $eventId);
        $event = $this->eventRow($eventId);
        $planId = $this->planId($eventId);
        $matches = $planId ? $this->matches($planId) : [];
        $sheets = DB::table('scor_sheet')->where('event', $eventId)->get();
        $status = [];
        foreach ($sheets as $sheet) {
            $status[$sheet->kind.'-'.$sheet->match.'-'.$sheet->team] = $this->statusOf($sheet);
        }

        $groups = $this->staffingGroups($eventId);
        $referees = [];
        foreach ($groups['referee'] as $group) {
            $referees[$group['index']] = $group['people'];
        }
        $lanes = $planId ? $this->juryLanes($planId) : [];

        $teams = [];
        foreach ($matches as &$match) {
            foreach (['team_1', 'team_2'] as $side) {
                $team = $match[$side];
                $table = (int) ($team['table'] ?? 0);
                $match[$side]['staff'] = $referees[$table] ?? [];
                if (empty($team['id'])) {
                    continue;
                }
                $teams[$team['id']] = $team;
                $match[$side]['referee_status'] = $status['referee-'.$match['id'].'-'.$team['id']] ?? 'open';
                $match[$side]['jury_status'] = $status['jury-0-'.$team['id']] ?? 'open';
            }
        }
        unset($match);

        foreach ($teams as $id => $team) {
            $teams[$id]['jury_lane'] = $lanes[(int) ($team['plan_slot'] ?? 0)] ?? null;
            $teams[$id]['jury_status'] = $status['jury-0-'.$id] ?? 'open';
        }

        return [
            'event' => [
                'id' => (int) $event->id,
                'name' => $event->name,
                'date' => $event->date,
            ],
            'roles' => [
                'referee' => $roles->allowsReferee(),
                'jury' => $roles->allowsJury(),
                'admin' => $roles->admin || $user->isFlowAdmin(),
            ],
            'has_plan' => $planId !== null,
            'groups' => $groups,
            'matches' => $matches,
            'teams' => array_values($teams),
        ];
    }

    /**
     * Standings in the shape of the Contao scoreboard.
     * Champion sums dense ranks (tied scores share a step). The table shows competition ranks.
     * Robot-Game is the best scoring round. The test round stays out.
     *
     * @return array{event: array{id: int, name: mixed}, rounds: list<array{round: int, label: string}>, teams: list<array<string, mixed>>}
     */
    public function results(User $user, int $eventId): array
    {
        $this->requireEvent($user, $eventId);
        $event = $this->eventRow($eventId);
        $planId = $this->planId($eventId);
        $matches = $planId ? $this->matches($planId) : [];
        $matchById = [];
        $rounds = [];
        $teams = [];
        foreach ($matches as $match) {
            $matchById[$match['id']] = $match;
            if ($match['round'] > 0 && !isset($rounds[$match['round']])) {
                $rounds[$match['round']] = [
                    'round' => $match['round'],
                    'label' => $match['round_label'],
                ];
            }
            foreach (['team_1', 'team_2'] as $side) {
                $team = $match[$side];
                if (empty($team['id']) || isset($teams[$team['id']])) {
                    continue;
                }
                $teams[$team['id']] = [
                    'id' => $team['id'],
                    'number' => $team['number'],
                    'name' => $team['name'],
                ];
            }
        }
        ksort($rounds);

        $sheets = DB::table('scor_sheet')->where('event', $eventId)->get();
        $referee = [];
        foreach ($sheets as $sheet) {
            if ($sheet->kind !== 'referee') {
                continue;
            }
            $match = $matchById[(int) $sheet->match] ?? null;
            $teamId = (int) $sheet->team;
            if (!$match || (int) $match['round'] < 1 || !isset($teams[$teamId])) {
                continue;
            }
            $round = (int) $match['round'];
            $points = (int) $sheet->points;
            $current = $referee[$teamId][$round] ?? null;
            if ($current !== null && $points < $current['points']) {
                continue;
            }
            $referee[$teamId][$round] = [
                'points' => $points,
                'confirmed' => (bool) $sheet->confirmed_referee && (bool) $sheet->confirmed_team,
            ];
        }

        $jury = $this->juryCategoryScores((int) $event->season, $sheets);
        $rows = [];
        foreach ($teams as $id => $team) {
            $cells = [];
            $best = null;
            foreach ($rounds as $round) {
                $cell = $referee[$id][$round['round']] ?? null;
                $cells[] = $cell === null ? null : [
                    'round' => $round['round'],
                    'points' => $cell['points'],
                    'confirmed' => $cell['confirmed'],
                ];
                if ($cell !== null && ($best === null || $cell['points'] > $best)) {
                    $best = $cell['points'];
                }
            }
            $categories = $jury[$id] ?? ['research' => 0, 'robotdesign' => 0, 'core_values' => 0];
            $rows[] = [
                'id' => $id,
                'number' => $team['number'],
                'name' => $team['name'],
                'rounds' => $cells,
                'robot' => $best ?? 0,
                'research' => $categories['research'],
                'robotdesign' => $categories['robotdesign'],
                'core_values' => $categories['core_values'],
            ];
        }

        $robotRank = $this->competitionRanks($rows, 'robot', false);
        $researchRank = $this->competitionRanks($rows, 'research', false);
        $designRank = $this->competitionRanks($rows, 'robotdesign', false);
        $coreRank = $this->competitionRanks($rows, 'core_values', false);
        $denseRobot = $this->denseRanks($rows, 'robot');
        $denseResearch = $this->denseRanks($rows, 'research');
        $denseDesign = $this->denseRanks($rows, 'robotdesign');
        $denseCore = $this->denseRanks($rows, 'core_values');
        foreach ($rows as &$row) {
            $id = $row['id'];
            $row['robot_rank'] = $robotRank[$id];
            $row['research_rank'] = $researchRank[$id];
            $row['robotdesign_rank'] = $designRank[$id];
            $row['core_values_rank'] = $coreRank[$id];
            $row['champion'] = $denseRobot[$id] + $denseResearch[$id] + $denseDesign[$id] + $denseCore[$id];
        }
        unset($row);

        $championRank = $this->competitionRanks($rows, 'champion', true);
        foreach ($rows as &$row) {
            $row['champion_rank'] = $championRank[$row['id']];
        }
        unset($row);

        usort($rows, function (array $a, array $b): int {
            if ($a['champion_rank'] !== $b['champion_rank']) {
                return $a['champion_rank'] <=> $b['champion_rank'];
            }

            return (int) $a['number'] <=> (int) $b['number'];
        });

        return [
            'event' => [
                'id' => (int) $event->id,
                'name' => $event->name,
            ],
            'rounds' => array_values($rounds),
            'teams' => $rows,
        ];
    }

    public function refereeSheet(User $user, int $eventId, int $matchId, int $teamId): array
    {
        $roles = $this->requireEvent($user, $eventId);
        if (!$roles->allowsReferee()) {
            throw new ScorAccessException('Kein Zugriff auf den Schiri-Bogen.');
        }

        $context = $this->matchTeam($eventId, $matchId, $teamId);
        $sheet = $this->findSheet($eventId, 'referee', $teamId, $matchId);
        $formId = $this->forms->formId((int) $this->eventRow($eventId)->season, 'referee');

        return $this->sheetPayload($eventId, 'referee', $context, $sheet, $formId, $roles, $user);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function saveReferee(User $user, int $eventId, int $matchId, int $teamId, array $answers): array
    {
        $this->refereeSheet($user, $eventId, $matchId, $teamId);
        $this->writeSheet($user, $eventId, 'referee', $teamId, $matchId, $answers, complete: false);

        return $this->refereeSheet($user, $eventId, $matchId, $teamId);
    }

    public function confirmReferee(User $user, int $eventId, int $matchId, int $teamId, string $side): array
    {
        if (!in_array($side, ['referee', 'team'], true)) {
            throw new ScorAccessException('Unbekannte Bestätigung.', 422);
        }
        $roles = $this->requireEvent($user, $eventId);
        if (!$roles->allowsReferee()) {
            throw new ScorAccessException('Kein Zugriff auf den Schiri-Bogen.');
        }
        $this->matchTeam($eventId, $matchId, $teamId);
        $formId = $this->forms->formId((int) $this->eventRow($eventId)->season, 'referee');
        $sheet = $this->findSheet($eventId, 'referee', $teamId, $matchId);
        if ($this->isLocked($sheet)) {
            throw new ScorAccessException('Bogen ist bestätigt.', 409);
        }
        $answers = $this->answersOf($sheet);
        $this->assertAnswered($formId, $answers, requireComments: false);

        $column = $side === 'referee' ? 'confirmed_referee' : 'confirmed_team';
        $this->storeFlags($eventId, 'referee', $teamId, $matchId, $answers, $formId, [
            $column => true,
        ]);

        return $this->refereeSheet($user, $eventId, $matchId, $teamId);
    }

    public function reopenReferee(User $user, int $eventId, int $matchId, int $teamId): array
    {
        $this->requireAdmin($user, $eventId);
        $this->matchTeam($eventId, $matchId, $teamId);
        DB::table('scor_sheet')
            ->where('event', $eventId)
            ->where('kind', 'referee')
            ->where('team', $teamId)
            ->where('match', $matchId)
            ->update([
                'confirmed_referee' => false,
                'confirmed_team' => false,
                'updated_at' => now(),
            ]);

        return $this->refereeSheet($user, $eventId, $matchId, $teamId);
    }

    public function jurySheet(User $user, int $eventId, int $teamId): array
    {
        $roles = $this->requireEvent($user, $eventId);
        if (!$roles->allowsJury()) {
            throw new ScorAccessException('Kein Zugriff auf den Jury-Bogen.');
        }
        $team = $this->teamOnEvent($eventId, $teamId);
        $sheet = $this->findSheet($eventId, 'jury', $teamId, 0);
        $formId = $this->forms->formId((int) $this->eventRow($eventId)->season, 'jury');

        return $this->sheetPayload($eventId, 'jury', ['team' => $team], $sheet, $formId, $roles, $user);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function saveJury(User $user, int $eventId, int $teamId, array $answers): array
    {
        $current = $this->jurySheet($user, $eventId, $teamId);
        if ($current['completed']) {
            throw new ScorAccessException('Bewertung ist abgeschlossen.', 409);
        }
        $this->writeSheet($user, $eventId, 'jury', $teamId, 0, $answers, complete: false);

        return $this->jurySheet($user, $eventId, $teamId);
    }

    public function completeJury(User $user, int $eventId, int $teamId): array
    {
        $current = $this->jurySheet($user, $eventId, $teamId);
        if ($current['completed']) {
            return $current;
        }
        $formId = $this->forms->formId((int) $this->eventRow($eventId)->season, 'jury');
        $sheet = $this->findSheet($eventId, 'jury', $teamId, 0);
        $answers = $this->answersOf($sheet);
        $this->assertAnswered($formId, $answers, requireComments: true);
        $this->storeFlags($eventId, 'jury', $teamId, 0, $answers, $formId, ['completed' => true]);

        return $this->jurySheet($user, $eventId, $teamId);
    }

    public function reopenJury(User $user, int $eventId, int $teamId): array
    {
        $this->requireAdmin($user, $eventId);
        $this->teamOnEvent($eventId, $teamId);
        DB::table('scor_sheet')
            ->where('event', $eventId)
            ->where('kind', 'jury')
            ->where('team', $teamId)
            ->where('match', 0)
            ->update(['completed' => false, 'updated_at' => now()]);

        return $this->jurySheet($user, $eventId, $teamId);
    }

    private function writeSheet(User $user, int $eventId, string $kind, int $teamId, int $matchId, array $answers, bool $complete): void
    {
        $sheet = $this->findSheet($eventId, $kind, $teamId, $matchId);
        if ($kind === 'referee' && $this->isLocked($sheet)) {
            throw new ScorAccessException('Bogen ist bestätigt.', 409);
        }
        if ($kind === 'jury' && $sheet && (bool) $sheet->completed) {
            throw new ScorAccessException('Bewertung ist abgeschlossen.', 409);
        }

        $formId = $this->forms->formId((int) $this->eventRow($eventId)->season, $kind);
        $normalized = $this->normalizeAnswers($answers);
        if ($sheet && $this->answersChanged($this->answersOf($sheet), $normalized)) {
            $this->storeFlags($eventId, $kind, $teamId, $matchId, $normalized, $formId, [
                'confirmed_referee' => false,
                'confirmed_team' => false,
                'completed' => $complete,
            ]);

            return;
        }

        $this->storeFlags($eventId, $kind, $teamId, $matchId, $normalized, $formId, [
            'completed' => $complete,
        ]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $flags
     */
    private function storeFlags(int $eventId, string $kind, int $teamId, int $matchId, array $answers, int $formId, array $flags): void
    {
        $scored = $this->score($formId, $answers);
        $existing = $this->findSheet($eventId, $kind, $teamId, $matchId);
        $row = array_merge([
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'points' => $scored['points'],
            'core_points' => $scored['core_points'],
            'updated_at' => now(),
        ], $flags);

        if ($existing) {
            DB::table('scor_sheet')->where('id', $existing->id)->update($row);

            return;
        }

        DB::table('scor_sheet')->insert(array_merge($row, [
            'event' => $eventId,
            'kind' => $kind,
            'team' => $teamId,
            'match' => $matchId,
            'confirmed_referee' => false,
            'confirmed_team' => false,
            'completed' => false,
        ], $flags));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sheetPayload(int $eventId, string $kind, array $context, ?object $sheet, int $formId, ScorGrant $roles, User $user): array
    {
        $answers = $this->answersOf($sheet);
        $scored = $this->score($formId, $answers);
        $event = $this->eventRow($eventId);
        $items = $this->forms->itemsFor($formId);
        $sections = [];
        foreach ($items as $item) {
            $sectionId = (int) $item->section_id;
            if (!isset($sections[$sectionId])) {
                $sections[$sectionId] = [
                    'id' => $sectionId,
                    'title' => $item->section_title,
                    'kind' => $item->section_kind,
                    'note' => $item->section_note,
                    'disabled' => in_array($sectionId, $answers['disabled_sections'], true),
                    'items' => [],
                ];
            }
            $itemId = (string) $item->id;
            $sections[$sectionId]['items'][] = [
                'id' => (int) $item->id,
                'item_type' => $item->item_type,
                'label' => $item->label,
                'hint' => $item->hint,
                'core_value' => (bool) $item->core_value,
                'config' => $this->decode($item->config),
                'value' => $answers['values'][$itemId] ?? ($answers['texts'][$itemId] ?? null),
                'comment' => $answers['comments'][$itemId] ?? '',
            ];
        }

        return array_merge($context, [
            'event' => ['id' => (int) $event->id, 'name' => $event->name],
            'kind' => $kind,
            'roles' => [
                'referee' => $roles->allowsReferee(),
                'jury' => $roles->allowsJury(),
                'admin' => $roles->admin || $user->isFlowAdmin(),
            ],
            'locked' => $kind === 'referee' ? $this->isLocked($sheet) : (bool) ($sheet->completed ?? false),
            'confirmed_referee' => (bool) ($sheet->confirmed_referee ?? false),
            'confirmed_team' => (bool) ($sheet->confirmed_team ?? false),
            'completed' => (bool) ($sheet->completed ?? false),
            'points' => $scored['points'],
            'core_points' => $scored['core_points'],
            'columns' => $kind === 'jury' ? FutureJuryCatalog::columns() : [],
            'core_values_note' => $kind === 'jury' ? FutureJuryCatalog::CORE_VALUES_NOTE : null,
            'sections' => array_values($sections),
        ]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{points: int, core_points: int}
     */
    private function score(int $formId, array $answers): array
    {
        $points = 0;
        $core = 0;
        foreach ($this->forms->itemsFor($formId) as $item) {
            if (in_array((int) $item->section_id, $answers['disabled_sections'], true)) {
                continue;
            }
            $value = $this->itemPoints($item, $answers);
            $points += $value;
            if ($item->item_type === 'rubric' && (bool) $item->core_value) {
                $core += $value;
            }
        }

        return ['points' => $points, 'core_points' => $core];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function itemPoints(object $item, array $answers): int
    {
        $config = $this->decode($item->config) ?? [];
        $raw = $answers['values'][(string) $item->id] ?? null;
        if ($raw === null || $raw === '') {
            return 0;
        }

        return match ($item->item_type) {
            'rubric' => $this->inRange((int) $raw, 1, 4) ? (int) $raw : 0,
            'yes_no' => $raw === 'yes'
                ? (int) ($config['yes_points'] ?? 0)
                : ($raw === 'no' ? (int) ($config['no_points'] ?? 0) : 0),
            'choice' => $this->choicePoints($config, (int) $raw) ?? 0,
            'count' => $this->countPoints($config, (int) $raw),
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function assertAnswered(int $formId, array $answers, bool $requireComments): void
    {
        foreach ($this->forms->itemsFor($formId) as $item) {
            if (in_array((int) $item->section_id, $answers['disabled_sections'], true)) {
                continue;
            }
            $id = (string) $item->id;
            if ($item->item_type === 'text') {
                if ($requireComments && mb_strlen(trim((string) ($answers['texts'][$id] ?? ''))) < 20) {
                    throw new ScorAccessException('Bitte in jedes Feedback mindestens 20 Zeichen eintragen.', 422);
                }
                continue;
            }

            $value = $answers['values'][$id] ?? null;
            if ($item->item_type === 'count') {
                continue;
            }
            if ($item->item_type === 'choice' && $this->choiceIsOptional($this->decode($item->config) ?? []) && ($value === null || $value === '')) {
                continue;
            }
            $answered = match ($item->item_type) {
                'rubric' => $this->inRange((int) $value, 1, 4),
                'yes_no' => $value === 'yes' || $value === 'no',
                'choice' => $this->choicePoints($this->decode($item->config) ?? [], is_numeric($value) ? (int) $value : -1) !== null,
                default => false,
            };
            if (!$answered) {
                throw new ScorAccessException('Bitte jede Zeile ausfüllen.', 422);
            }
            if ($requireComments && $item->item_type === 'rubric' && (int) $value === 4) {
                if (mb_strlen(trim((string) ($answers['comments'][$id] ?? ''))) < 20) {
                    throw new ScorAccessException('Bitte bei Herausragend einen Kommentar mit mindestens 20 Zeichen eintragen.', 422);
                }
            }
        }
    }

    /**
     * A single option is a flag (Kein Erscheinen): leaving it off is a valid answer.
     *
     * @param  array<string, mixed>  $config
     */
    private function choiceIsOptional(array $config): bool
    {
        if (!empty($config['optional'])) {
            return true;
        }
        $options = $config['options'] ?? [];

        return is_array($options) && count($options) === 1;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function choicePoints(array $config, int $index): ?int
    {
        $options = $config['options'] ?? [];
        if (!isset($options[$index]) || !is_array($options[$index])) {
            return null;
        }

        return (int) ($options[$index]['points'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function countPoints(array $config, int $count): int
    {
        $max = max(0, (int) ($config['max'] ?? 0));
        $count = max(0, min($max, $count));

        return $count * (int) ($config['points_each'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, comments: array<string, string>, texts: array<string, string>, disabled_sections: list<int>}
     */
    private function normalizeAnswers(array $input): array
    {
        $values = [];
        foreach ($input['values'] ?? [] as $id => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $values[(string) $id] = is_string($value) ? $value : (int) $value;
        }
        $comments = [];
        foreach ($input['comments'] ?? [] as $id => $value) {
            $comments[(string) $id] = trim((string) $value);
        }
        $texts = [];
        foreach ($input['texts'] ?? [] as $id => $value) {
            $texts[(string) $id] = trim((string) $value);
        }
        $disabled = array_values(array_unique(array_map('intval', $input['disabled_sections'] ?? [])));

        return [
            'values' => $values,
            'comments' => $comments,
            'texts' => $texts,
            'disabled_sections' => $disabled,
        ];
    }

    /**
     * @return array{values: array<string, mixed>, comments: array<string, string>, texts: array<string, string>, disabled_sections: list<int>}
     */
    private function answersOf(?object $sheet): array
    {
        if (!$sheet || $sheet->answers === null || $sheet->answers === '') {
            return ['values' => [], 'comments' => [], 'texts' => [], 'disabled_sections' => []];
        }
        $decoded = is_array($sheet->answers) ? $sheet->answers : json_decode((string) $sheet->answers, true);
        if (!is_array($decoded)) {
            return ['values' => [], 'comments' => [], 'texts' => [], 'disabled_sections' => []];
        }

        return $this->normalizeAnswers($decoded);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function answersChanged(array $left, array $right): bool
    {
        return json_encode($left) !== json_encode($right);
    }

    private function isLocked(?object $sheet): bool
    {
        return $sheet !== null && (bool) $sheet->confirmed_referee && (bool) $sheet->confirmed_team;
    }

    private function statusOf(object $sheet): string
    {
        if ($sheet->kind === 'jury') {
            return $sheet->completed ? 'done' : 'draft';
        }

        return ((bool) $sheet->confirmed_referee && (bool) $sheet->confirmed_team) ? 'done' : 'draft';
    }

    private function requireEvent(User $user, int $eventId): ScorGrant
    {
        $event = DB::table('event')->where('id', $eventId)->first();
        if (!$event) {
            throw new ScorAccessException('Veranstaltung nicht gefunden.', 404);
        }
        if (!$event->season) {
            throw new ScorAccessException('Die Veranstaltung hat keine Saison.', 422);
        }
        $onProgram = DB::table('event_program')
            ->where('event', $eventId)
            ->where('first_program', FirstProgram::FUTURE_8->value)
            ->exists();
        if (!$onProgram) {
            throw new ScorAccessException('Diese Veranstaltung hat kein Future.', 422);
        }

        if ($user->isFlowAdmin()) {
            return ScorGrant::all();
        }

        $roles = $this->roles($user, $eventId);
        if (!$roles->any()) {
            throw new ScorAccessException('Kein Zugriff auf diese Veranstaltung.');
        }

        return $roles;
    }

    private function requireAdmin(User $user, int $eventId): void
    {
        $roles = $this->requireEvent($user, $eventId);
        if (!$roles->admin && !$user->isFlowAdmin()) {
            throw new ScorAccessException('Wieder öffnen geht nur mit dem Recht Veranstaltung-Admin.');
        }
    }

    private function roles(User $user, int $eventId): ScorGrant
    {
        if ($user->isFlowAdmin()) {
            return ScorGrant::all();
        }

        $event = DB::table('event')->where('id', $eventId)->first();
        $draht = null;
        if ($event) {
            $draht = DB::table('user_regional_partner')
                ->where('user', $user->id)
                ->where('regional_partner', $event->regional_partner)
                ->where('source', FlowAccess::SOURCE_DRAHT)
                ->first();
        }
        $direct = DB::table('event_user_access')
            ->where('user', $user->id)
            ->where('event', $eventId)
            ->first();

        return ScorGrant::fromRow($draht)->or(ScorGrant::fromRow($direct));
    }

    /**
     * @param  list<int>|null  $limit
     * @return list<int>
     */
    private function futureEventIds(?array $limit): array
    {
        if ($limit !== null && $limit === []) {
            return [];
        }

        $query = DB::table('event_program')
            ->where('first_program', FirstProgram::FUTURE_8->value);
        if ($limit !== null) {
            $query->whereIn('event', $limit);
        }

        return array_values(array_unique(array_map('intval', $query->pluck('event')->all())));
    }

    private function eventRow(int $eventId): object
    {
        $event = DB::table('event')->where('id', $eventId)->first();
        if (!$event) {
            throw new ScorAccessException('Veranstaltung nicht gefunden.', 404);
        }

        return $event;
    }

    private function planId(int $eventId): ?int
    {
        $id = DB::table('plan')->where('event', $eventId)->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matches(int $planId): array
    {
        $rows = DB::table('match')
            ->where('plan', $planId)
            ->where('first_program', FirstProgram::FUTURE_8->value)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        $teamMap = DB::table('team_plan')
            ->join('team', 'team.id', '=', 'team_plan.team')
            ->where('team_plan.plan', $planId)
            ->where('team.first_program', FirstProgram::FUTURE_8->value)
            ->get(['team_plan.team_number_plan', 'team.id', 'team.name', 'team.team_number_hot'])
            ->keyBy('team_number_plan');

        $tableCount = 0;
        foreach ($rows as $row) {
            $tableCount = max($tableCount, (int) $row->table_1, (int) $row->table_2);
        }
        $tableNames = $this->tableNames($planId);
        $starts = $this->matchStarts($planId);

        $matches = [];
        foreach ($rows as $row) {
            $round = (int) $row->round;
            $key = $this->pairingKey(
                $round,
                (int) $row->table_1,
                (int) $row->table_1_team,
                (int) $row->table_2,
                (int) $row->table_2_team,
            );
            $start = $starts[$key] ?? null;
            $matches[] = [
                'id' => (int) $row->id,
                'round' => $round,
                'round_label' => $round === 0 ? 'Testrunde' : 'Runde '.$round,
                'match_no' => (int) $row->match_no,
                'starts_at' => $start,
                'start_label' => $this->clockLabel($start),
                'team_1' => $this->side($teamMap, (int) $row->table_1_team, (int) $row->table_1, $tableNames, $tableCount)
                    ?? $this->emptySide((int) $row->table_1, $tableNames, $tableCount),
                'team_2' => $this->side($teamMap, (int) $row->table_2_team, (int) $row->table_2, $tableNames, $tableCount)
                    ?? $this->emptySide((int) $row->table_2, $tableNames, $tableCount),
            ];
        }

        usort($matches, function (array $a, array $b): int {
            if ($a['round'] !== $b['round']) {
                return $a['round'] <=> $b['round'];
            }
            $start = strcmp($a['starts_at'] ?? '9999', $b['starts_at'] ?? '9999');
            if ($start !== 0) {
                return $start;
            }

            return $a['match_no'] <=> $b['match_no'];
        });

        return $matches;
    }

    /**
     * @return array<string, string> pairing key => activity start
     */
    private function matchStarts(int $planId): array
    {
        if (!Schema::hasTable('activity') || !Schema::hasTable('m_activity_type_detail')) {
            return [];
        }

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_activity_type_detail as group_atd', 'ag.activity_type_detail', '=', 'group_atd.id')
            ->where('ag.plan', $planId)
            ->where('atd.code', 'f8_r_match')
            ->where('atd.first_program', FirstProgram::FUTURE_8->value)
            ->get([
                'a.start',
                'a.table_1',
                'a.table_1_team',
                'a.table_2',
                'a.table_2_team',
                'group_atd.code as group_code',
            ]);

        $starts = [];
        foreach ($rows as $row) {
            $round = $this->roundFromGroupCode($row->group_code ?? null);
            if ($round === null || $row->start === null) {
                continue;
            }
            $key = $this->pairingKey(
                $round,
                (int) $row->table_1,
                (int) ($row->table_1_team ?? 0),
                (int) $row->table_2,
                (int) ($row->table_2_team ?? 0),
            );
            $start = (string) $row->start;
            if (!isset($starts[$key]) || $start < $starts[$key]) {
                $starts[$key] = $start;
            }
        }

        return $starts;
    }

    private function roundFromGroupCode(?string $code): ?int
    {
        if ($code === 'f8_test_round') {
            return 0;
        }
        if (is_string($code) && preg_match('/^f8_round_(\d+)$/', $code, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function pairingKey(int $round, int $table1, int $team1, int $table2, int $team2): string
    {
        return $round.'|'.$table1.'|'.$team1.'|'.$table2.'|'.$team2;
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(int $planId): array
    {
        if (!Schema::hasTable('table_event') || !Schema::hasColumn('table_event', 'table_name')) {
            return [];
        }
        $eventId = (int) DB::table('plan')->where('id', $planId)->value('event');
        if ($eventId < 1) {
            return [];
        }
        $query = DB::table('table_event')->where('event', $eventId);
        if (Schema::hasColumn('table_event', 'first_program')) {
            $query->where('first_program', FirstProgram::FUTURE_8->value);
        }
        $names = [];
        foreach ($query->get(['table_number', 'table_name']) as $row) {
            $name = trim((string) ($row->table_name ?? ''));
            if ($name !== '') {
                $names[(int) $row->table_number] = $name;
            }
        }

        return $names;
    }

    private function clockLabel(?string $start): ?string
    {
        if ($start === null || $start === '') {
            return null;
        }
        try {
            return Carbon::parse($start, 'Europe/Berlin')->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $tableNames
     * @return array{id: int, name: string, number: mixed, table: int, table_label: string}|null
     */
    private function side($teamMap, int $slot, int $table, array $tableNames, int $tableCount): ?array
    {
        if ($slot <= 0 || !isset($teamMap[$slot])) {
            return null;
        }
        $team = $teamMap[$slot];

        return [
            'id' => (int) $team->id,
            'name' => (string) $team->name,
            'number' => $team->team_number_hot,
            'plan_slot' => $slot,
            'table' => $table,
            'table_label' => $this->tableLabel($table, $tableNames, $tableCount),
        ];
    }

    /**
     * @param  array<int, string>  $tableNames
     * @return array{id: null, name: null, number: null, table: int, table_label: string}
     */
    private function emptySide(int $table, array $tableNames, int $tableCount): array
    {
        return [
            'id' => null,
            'name' => null,
            'number' => null,
            'table' => $table,
            'table_label' => $this->tableLabel($table, $tableNames, $tableCount),
        ];
    }

    /**
     * @param  array<int, string>  $tableNames
     */
    private function tableLabel(int $table, array $tableNames, int $tableCount): string
    {
        return TableFieldLabels::effective(
            FirstProgram::FUTURE_8->value,
            $table,
            $tableNames[$table] ?? null,
            $tableCount,
        );
    }

    /**
     * @return array{match: array<string, mixed>, team: array<string, mixed>, opponent: array<string, mixed>|null}
     */
    private function matchTeam(int $eventId, int $matchId, int $teamId): array
    {
        $planId = $this->planId($eventId);
        if (!$planId) {
            throw new ScorAccessException('Für diese Veranstaltung gibt es noch keinen Matchplan.', 422);
        }
        $match = collect($this->matches($planId))->firstWhere('id', $matchId);
        if (!$match) {
            throw new ScorAccessException('Match nicht gefunden.', 404);
        }
        $team = null;
        $opponent = null;
        foreach (['team_1', 'team_2'] as $side) {
            $other = $side === 'team_1' ? 'team_2' : 'team_1';
            if (($match[$side]['id'] ?? null) === $teamId) {
                $team = $match[$side];
                $opponent = $match[$other];
            }
        }
        if (!$team) {
            throw new ScorAccessException('Dieses Team spielt in dem Match nicht.', 404);
        }

        return ['match' => $match, 'team' => $team, 'opponent' => $opponent];
    }

    /**
     * @return array{id: int, name: string, number: mixed}
     */
    private function teamOnEvent(int $eventId, int $teamId): array
    {
        $planId = $this->planId($eventId);
        if (!$planId) {
            throw new ScorAccessException('Für diese Veranstaltung gibt es noch keinen Matchplan.', 422);
        }
        foreach ($this->matches($planId) as $match) {
            foreach (['team_1', 'team_2'] as $side) {
                if (($match[$side]['id'] ?? null) === $teamId) {
                    return [
                        'id' => $teamId,
                        'name' => $match[$side]['name'],
                        'number' => $match[$side]['number'],
                    ];
                }
            }
        }

        throw new ScorAccessException('Team nicht gefunden.', 404);
    }

    private function findSheet(int $eventId, string $kind, int $teamId, int $matchId): ?object
    {
        return DB::table('scor_sheet')
            ->where('event', $eventId)
            ->where('kind', $kind)
            ->where('team', $teamId)
            ->where('match', $matchId)
            ->first();
    }

    private function decode(mixed $config): ?array
    {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || $config === '') {
            return null;
        }
        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Future jury lanes and referee tables, with the people assigned in staffing.
     *
     * @return array{jury: list<array{index: int, label: string, people: list<string>}>, referee: list<array{index: int, label: string, people: list<string>}>}
     */
    private function staffingGroups(int $eventId): array
    {
        $empty = ['jury' => [], 'referee' => []];
        foreach (['event_staffing_role', 'event_staffing_group', 'event_staffing_assignment', 'volunteer_person', 'm_role'] as $table) {
            if (!Schema::hasTable($table)) {
                return $empty;
            }
        }

        $roles = DB::table('event_staffing_role as r')
            ->join('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->where('r.event', $eventId)
            ->where('mr.first_program', FirstProgram::FUTURE_8->value)
            ->whereIn('mr.differentiation_parameter', ['lane', 'table'])
            ->where('r.surplus', false)
            ->orderBy('r.sequence')
            ->orderBy('r.id')
            ->get([
                'r.id',
                'r.label',
                'r.group_label',
                'mr.group_label as catalog_group_label',
                'mr.differentiation_parameter',
            ]);
        if ($roles->isEmpty()) {
            return $empty;
        }

        $roleKind = [];
        $roleLabel = [];
        foreach ($roles as $role) {
            $roleKind[(int) $role->id] = $role->differentiation_parameter === 'lane' ? 'jury' : 'referee';
            $base = trim((string) ($role->group_label ?: $role->catalog_group_label ?: $role->label));
            $roleLabel[(int) $role->id] = $base;
        }

        $groups = DB::table('event_staffing_group')
            ->whereIn('event_staffing_role', array_keys($roleKind))
            ->where('surplus', false)
            ->orderBy('group_index')
            ->get(['id', 'event_staffing_role', 'group_index']);
        if ($groups->isEmpty()) {
            return $empty;
        }

        $peopleByGroup = [];
        $assignments = DB::table('event_staffing_assignment as a')
            ->join('volunteer_person as p', 'p.id', '=', 'a.volunteer_person')
            ->whereIn('a.event_staffing_group', $groups->pluck('id'))
            ->orderBy('p.last_name')
            ->orderBy('p.first_name')
            ->get(['a.event_staffing_group', 'p.first_name', 'p.last_name']);
        foreach ($assignments as $assignment) {
            $name = trim($assignment->first_name.' '.$assignment->last_name);
            if ($name === '') {
                continue;
            }
            $peopleByGroup[(int) $assignment->event_staffing_group][] = $name;
        }

        $merged = ['jury' => [], 'referee' => []];
        foreach ($groups as $group) {
            $roleId = (int) $group->event_staffing_role;
            $kind = $roleKind[$roleId];
            $index = (int) $group->group_index;
            $base = $roleLabel[$roleId];
            $label = $base === '' ? 'Gruppe '.$index : $base.' '.$index;
            if (!isset($merged[$kind][$index])) {
                $merged[$kind][$index] = [
                    'index' => $index,
                    'label' => $label,
                    'people' => [],
                ];
            }
            foreach ($peopleByGroup[(int) $group->id] ?? [] as $name) {
                if (!in_array($name, $merged[$kind][$index]['people'], true)) {
                    $merged[$kind][$index]['people'][] = $name;
                }
            }
        }

        return [
            'jury' => array_values($merged['jury']),
            'referee' => array_values($merged['referee']),
        ];
    }

    /**
     * @return array<int, int> plan slot => jury lane
     */
    private function juryLanes(int $planId): array
    {
        if (!Schema::hasTable('activity') || !Schema::hasTable('activity_group') || !Schema::hasTable('m_activity_type_detail')) {
            return [];
        }

        return (new TeamJuryAssignmentService())->assignmentsForProgram($planId, FirstProgram::FUTURE_8->value);
    }

    private function inRange(int $value, int $min, int $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * Forschung and Roboterdesign are the rubric sections. Grundwerte are the rows flagged as core values.
     *
     * @param  iterable<object>  $sheets
     * @return array<int, array{research: int, robotdesign: int, core_values: int}>
     */
    private function juryCategoryScores(int $seasonId, iterable $sheets): array
    {
        $items = $this->forms->itemsFor($this->forms->formId($seasonId, 'jury'));
        $sectionCategory = [];
        $rubricIndex = 0;
        foreach ($items as $item) {
            $sectionId = (int) $item->section_id;
            if (isset($sectionCategory[$sectionId]) || $item->section_kind !== 'rubric') {
                continue;
            }
            $sectionCategory[$sectionId] = $this->juryCategory((string) $item->section_title, $rubricIndex);
            $rubricIndex++;
        }

        $byTeam = [];
        foreach ($sheets as $sheet) {
            if ($sheet->kind !== 'jury' || (int) $sheet->match !== 0) {
                continue;
            }
            $answers = $this->answersOf($sheet);
            $scores = ['research' => 0, 'robotdesign' => 0, 'core_values' => 0];
            foreach ($items as $item) {
                if (in_array((int) $item->section_id, $answers['disabled_sections'], true)) {
                    continue;
                }
                $points = $this->itemPoints($item, $answers);
                $category = $sectionCategory[(int) $item->section_id] ?? null;
                if ($category !== null) {
                    $scores[$category] += $points;
                }
                if ($item->item_type === 'rubric' && (bool) $item->core_value) {
                    $scores['core_values'] += $points;
                }
            }
            $byTeam[(int) $sheet->team] = $scores;
        }

        return $byTeam;
    }

    private function juryCategory(string $title, int $index): ?string
    {
        $title = mb_strtolower($title);
        if (str_contains($title, 'forschung') || str_contains($title, 'research')) {
            return 'research';
        }
        if (str_contains($title, 'roboter') || str_contains($title, 'robot')) {
            return 'robotdesign';
        }

        return match ($index) {
            0 => 'research',
            1 => 'robotdesign',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function competitionRanks(array $rows, string $scoreKey, bool $lowerIsBetter): array
    {
        $ordered = $rows;
        usort($ordered, function (array $a, array $b) use ($scoreKey, $lowerIsBetter): int {
            if ($a[$scoreKey] !== $b[$scoreKey]) {
                return $lowerIsBetter ? $a[$scoreKey] <=> $b[$scoreKey] : $b[$scoreKey] <=> $a[$scoreKey];
            }

            return $a['id'] <=> $b['id'];
        });

        $rank = 1;
        $tied = 0;
        $last = null;
        $out = [];
        foreach ($ordered as $row) {
            if ($last !== null) {
                $worse = $lowerIsBetter ? $row[$scoreKey] > $last[$scoreKey] : $row[$scoreKey] < $last[$scoreKey];
                if ($worse) {
                    $rank++;
                    $rank += $tied;
                    $tied = 0;
                }
                if ($row[$scoreKey] === $last[$scoreKey]) {
                    $tied++;
                }
            }
            $out[$row['id']] = $rank;
            $last = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function denseRanks(array $rows, string $scoreKey): array
    {
        $ordered = $rows;
        usort($ordered, function (array $a, array $b) use ($scoreKey): int {
            if ($a[$scoreKey] !== $b[$scoreKey]) {
                return $b[$scoreKey] <=> $a[$scoreKey];
            }

            return $a['id'] <=> $b['id'];
        });

        $rank = 1;
        $last = null;
        $out = [];
        foreach ($ordered as $row) {
            if ($last !== null && $row[$scoreKey] < $last[$scoreKey]) {
                $rank++;
            }
            $out[$row['id']] = $rank;
            $last = $row;
        }

        return $out;
    }
}
