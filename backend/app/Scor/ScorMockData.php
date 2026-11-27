<?php

namespace App\Scor;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dev/Test helper: wipe the event's evaluation and fill every referee and jury sheet with random answers.
 * Sheets go through the regular save and confirm paths, so points follow the season rules.
 * Awards stay empty so the results can be played with.
 */
final class ScorMockData
{
    private const PRODUCTION_HOSTS = ['flow.hands-on-technology.org', 'score.hands-on-technology.org'];

    private const FEEDBACK = [
        'Das Team hat seine Ideen klar und mit viel Begeisterung vorgestellt.',
        'Die Aufgaben waren gut verteilt, alle im Team kamen zu Wort.',
        'Der Roboter war durchdacht gebaut und wurde sicher erklärt.',
        'Bei der Recherche lohnt es sich, noch mehr Quellen zu vergleichen.',
        'Die Präsentation war lebendig und gut strukturiert.',
        'Das Team ist auf Rückfragen offen und freundlich eingegangen.',
    ];

    public function __construct(private ScorSheetService $sheets, private ScorFormStore $forms) {}

    public static function available(): bool
    {
        if (app()->environment('production')) {
            return false;
        }
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return !in_array($host, self::PRODUCTION_HOSTS, true);
    }

    /**
     * @return array{referee: int, jury: int}
     */
    public function fill(User $user, int $eventId): array
    {
        if (!self::available()) {
            throw new ScorAccessException('Testdaten gibt es nur auf Dev und Test.');
        }
        $overview = $this->sheets->overview($user, $eventId);
        if (!$overview['roles']['admin']) {
            throw new ScorAccessException('Testdaten füllt nur der Veranstaltung-Admin.');
        }
        if (!$overview['has_plan'] || $overview['matches'] === []) {
            throw new ScorAccessException('Für diese Veranstaltung gibt es noch keine Future-Matches.', 422);
        }

        $season = (int) DB::table('event')->where('id', $eventId)->value('season');
        $refereeItems = $this->forms->itemsFor($this->forms->formId($season, 'referee'));
        $juryItems = $this->forms->itemsFor($this->forms->formId($season, 'jury'));
        $retreat = FutureRefereeRules::retreatItem($refereeItems);

        $robotSkill = [];
        $jurySkill = [];
        foreach ($overview['teams'] as $team) {
            $robotSkill[(int) $team['id']] = $this->random(0.2, 0.95);
            $jurySkill[(int) $team['id']] = $this->random(0.15, 0.95);
        }

        return DB::transaction(function () use ($user, $eventId, $overview, $refereeItems, $juryItems, $retreat, $robotSkill, $jurySkill) {
            $this->reset($eventId);

            $referee = 0;
            foreach ($overview['matches'] as $match) {
                $teamIds = array_values(array_filter([
                    (int) ($match['team_1']['id'] ?? 0),
                    (int) ($match['team_2']['id'] ?? 0),
                ]));
                $shared = $retreat ? $this->value($retreat, $this->random(0.2, 0.9)) : null;
                foreach ($teamIds as $teamId) {
                    $answers = $this->answers($refereeItems, $robotSkill[$teamId] ?? 0.5);
                    if ($retreat) {
                        unset($answers['values'][(string) $retreat->id]);
                        if ($shared !== null) {
                            $answers['values'][(string) $retreat->id] = $shared;
                        }
                    }
                    $this->sheets->saveReferee($user, $eventId, (int) $match['id'], $teamId, $answers);
                    $this->sheets->confirmReferee($user, $eventId, (int) $match['id'], $teamId, 'referee');
                    $this->sheets->confirmReferee($user, $eventId, (int) $match['id'], $teamId, 'team');
                    $referee++;
                }
            }

            $jury = 0;
            foreach ($overview['teams'] as $team) {
                $teamId = (int) $team['id'];
                $this->sheets->saveJury($user, $eventId, $teamId, $this->answers($juryItems, $jurySkill[$teamId] ?? 0.5));
                $this->sheets->completeJury($user, $eventId, $teamId);
                $jury++;
            }

            return ['referee' => $referee, 'jury' => $jury];
        });
    }

    private function reset(int $eventId): void
    {
        foreach (['scor_sheet', 'scor_award'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('event', $eventId)->delete();
            }
        }
        if (Schema::hasTable('scor_event')) {
            DB::table('scor_event')->where('event', $eventId)->update(['closed_at' => null, 'released_at' => null]);
        }
    }

    /**
     * @param  list<object>  $items
     * @return array{values: array<string, mixed>, comments: array<string, string>, texts: array<string, string>, disabled_sections: list<int>}
     */
    private function answers(array $items, float $skill): array
    {
        $values = [];
        $comments = [];
        $texts = [];
        foreach ($items as $item) {
            $id = (string) $item->id;
            if ($item->item_type === 'text') {
                $texts[$id] = $this->feedback();

                continue;
            }
            $value = $this->value($item, $skill);
            if ($value === null) {
                continue;
            }
            $values[$id] = $value;
            if ($item->item_type === 'rubric' && $value === 4) {
                $comments[$id] = $this->feedback();
            }
        }

        $bonus = $this->itemByLabel($items, FutureRefereeRules::QUEEN_BONUS);
        $forest = $this->itemByLabel($items, FutureRefereeRules::YOUNG_FOREST);
        if ($bonus && ($values[(string) $bonus->id] ?? null) === 'yes' && (int) ($forest ? ($values[(string) $forest->id] ?? 0) : 0) < 3) {
            $values[(string) $bonus->id] = 'no';
        }

        return ['values' => $values, 'comments' => $comments, 'texts' => $texts, 'disabled_sections' => []];
    }

    private function value(object $item, float $skill): int|string|null
    {
        $config = $this->config($item);

        return match ($item->item_type) {
            'rubric' => max(1, min(4, (int) round(1 + $skill * 3 + $this->random(-0.8, 0.8)))),
            'yes_no' => $this->random(0, 1) < $skill ? 'yes' : 'no',
            'count' => $this->count($config, $skill),
            'choice' => $this->choice($config, $skill),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function count(array $config, float $skill): int
    {
        $max = isset($config['max']) && $config['max'] !== '' && $config['max'] !== null ? max(0, (int) $config['max']) : 5;

        return max(0, min($max, (int) round($max * ($skill + $this->random(-0.25, 0.25)))));
    }

    /**
     * Options ordered by points; stronger teams land higher. Single-option flags (Kein Erscheinen) stay off,
     * and options that zero the match are never picked.
     *
     * @param  array<string, mixed>  $config
     */
    private function choice(array $config, float $skill): ?int
    {
        $options = $config['options'] ?? [];
        if (!is_array($options) || count($options) < 2) {
            return null;
        }
        $candidates = [];
        foreach ($options as $index => $option) {
            if (!is_array($option) || !empty($option['zeros_match']) || in_array((string) ($option['label'] ?? ''), ['Dritte', '3 oder mehr'], true)) {
                continue;
            }
            $candidates[(int) $index] = (int) ($option['points'] ?? 0);
        }
        if ($candidates === []) {
            return null;
        }
        asort($candidates);
        $ordered = array_keys($candidates);
        $position = (int) round((count($ordered) - 1) * max(0, min(1, $skill + $this->random(-0.3, 0.3))));

        return $ordered[$position];
    }

    /**
     * @param  list<object>  $items
     */
    private function itemByLabel(array $items, string $label): ?object
    {
        foreach ($items as $item) {
            if ((string) $item->label === $label) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(object $item): array
    {
        if (is_array($item->config ?? null)) {
            return $item->config;
        }
        $decoded = json_decode((string) ($item->config ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function feedback(): string
    {
        return self::FEEDBACK[array_rand(self::FEEDBACK)];
    }

    private function random(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
