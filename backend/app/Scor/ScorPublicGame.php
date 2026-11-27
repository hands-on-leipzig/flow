<?php

namespace App\Scor;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Future Spiel results for the carousel, round by round.
 * Only rounds released in the Cockpit are shown, and only sheets confirmed by referee and team count.
 * Average and rank follow the shown rounds; a missing sheet counts as 0.
 */
final class ScorPublicGame
{
    public function __construct(private ScorSheetService $sheets) {}

    /**
     * @return array{rounds: list<array{round: int, label: string, visible: bool}>}
     */
    public function rounds(int $eventId): array
    {
        $visible = $this->visibleRounds($eventId);

        return [
            'rounds' => array_map(
                fn (array $round) => $round + ['visible' => in_array($round['round'], $visible, true)],
                $this->sheets->scoringRounds($eventId),
            ),
        ];
    }

    /**
     * @param  list<mixed>  $rounds
     * @return array{rounds: list<array{round: int, label: string, visible: bool}>}
     */
    public function saveRounds(int $eventId, array $rounds): array
    {
        if (!Schema::hasColumn('scor_event', 'public_rounds')) {
            throw new ScorAccessException('Die Freigabe der Runden ist noch nicht eingerichtet.', 503);
        }

        $known = array_column($this->sheets->scoringRounds($eventId), 'round');
        $picked = array_values(array_intersect($known, array_map('intval', $rounds)));
        $value = json_encode($picked);

        if (DB::table('scor_event')->where('event', $eventId)->exists()) {
            DB::table('scor_event')->where('event', $eventId)->update(['public_rounds' => $value]);
        } else {
            DB::table('scor_event')->insert(['event' => $eventId, 'public_rounds' => $value]);
        }

        return $this->rounds($eventId);
    }

    /**
     * @return array{event: array{id: int, name: mixed}, rounds: list<array{round: int, label: string}>, teams: list<array<string, mixed>>}
     */
    public function game(int $eventId): array
    {
        $user = new User;
        $user->id = 0;
        $results = $this->sheets->results($user, $eventId, public: true);

        $visible = $this->visibleRounds($eventId);
        $rounds = array_values(array_filter(
            $results['rounds'],
            fn (array $round) => in_array((int) $round['round'], $visible, true),
        ));

        $rows = [];
        foreach ($results['teams'] as $team) {
            $byRound = [];
            foreach ($team['rounds'] as $cell) {
                if ($cell !== null && $cell['confirmed']) {
                    $byRound[(int) $cell['round']] = (int) $cell['points'];
                }
            }
            $points = array_map(fn (array $round) => $byRound[(int) $round['round']] ?? null, $rounds);
            $rows[] = [
                'id' => (int) $team['id'],
                'number' => $team['number'],
                'name' => $team['name'],
                'points' => $points,
                'average' => $rounds === [] ? 0.0 : (float) array_sum(array_filter($points)) / count($rounds),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['average'] !== $b['average']) {
                return $b['average'] <=> $a['average'];
            }

            return (int) $a['number'] <=> (int) $b['number'];
        });
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index > 0 && $row['average'] === $rows[$index - 1]['average']
                ? $rows[$index - 1]['rank']
                : $index + 1;
        }
        unset($row);

        return [
            'event' => $results['event'],
            'rounds' => $rounds,
            'teams' => $rounds === [] ? [] : $rows,
        ];
    }

    /**
     * @return list<int>
     */
    private function visibleRounds(int $eventId): array
    {
        if (!Schema::hasTable('scor_event') || !Schema::hasColumn('scor_event', 'public_rounds')) {
            return [];
        }
        $raw = DB::table('scor_event')->where('event', $eventId)->value('public_rounds');
        $rounds = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($rounds) ? array_values(array_map('intval', $rounds)) : [];
    }
}
