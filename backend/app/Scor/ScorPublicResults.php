<?php

namespace App\Scor;

/**
 * Released results for visitors.
 * Awards carry teams only: podium, nominees, trophy holders. No points, no ranks, no overall ranking.
 * Spiel carries every scoring round with points, the average and the Spiel rank.
 * No jury points and no jury ranks anywhere.
 */
final class ScorPublicResults
{
    /**
     * @param  array<string, mixed>  $results  output of ScorSheetService::results
     * @return array<string, mixed>
     */
    public function fromResults(array $results): array
    {
        $teams = [];
        foreach ($results['teams'] as $team) {
            $teams[(int) $team['id']] = $team;
        }
        $awards = $results['awards'] ?? ['trophies' => [], 'nominations' => [], 'coaching' => null];

        $trophyHolder = [];
        foreach ($awards['trophies'] as $row) {
            $trophyHolder[(string) $row['category']] = (int) $row['team'];
        }

        $podium = [];
        foreach (ScorAwards::PODIUM as $place) {
            if (isset($trophyHolder[$place], $teams[$trophyHolder[$place]])) {
                $podium[] = ['place' => $place, 'team' => $this->team($teams[$trophyHolder[$place]])];
            }
        }

        $categories = [];
        foreach (ScorAwards::NOMINATED as $category) {
            $nominees = [];
            foreach ($awards['nominations'] as $row) {
                $id = (int) $row['team'];
                if ((string) $row['category'] === $category && isset($teams[$id])) {
                    $nominees[] = $this->team($teams[$id]);
                }
            }
            usort($nominees, fn (array $a, array $b) => (int) $a['number'] <=> (int) $b['number']);
            $winner = $trophyHolder[$category] ?? null;
            $categories[] = [
                'category' => $category,
                'nominees' => $nominees,
                'winner' => $winner !== null && isset($teams[$winner]) ? $this->team($teams[$winner]) : null,
            ];
        }

        $coaching = $awards['coaching'] ?? null;

        $game = array_values($teams);
        usort($game, function (array $a, array $b): int {
            if ($a['robot_rank'] !== $b['robot_rank']) {
                return $a['robot_rank'] <=> $b['robot_rank'];
            }

            return (int) $a['number'] <=> (int) $b['number'];
        });

        $gameWinners = array_values(array_filter(
            $game,
            fn (array $team) => (int) $team['robot_rank'] === 1 && $team['robot'] > 0,
        ));

        return [
            'event' => $results['event'],
            'awards' => [
                'podium' => $podium,
                'categories' => $categories,
                'coaching' => $coaching && isset($teams[(int) $coaching]) ? $this->team($teams[(int) $coaching]) : null,
                'game' => array_map(fn (array $team) => $this->team($team), $gameWinners),
            ],
            'game' => [
                'rounds' => $results['rounds'],
                'teams' => array_map(fn (array $team) => $this->gameRow($team), $game),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array<string, mixed>
     */
    private function gameRow(array $team): array
    {
        $cells = array_map(
            fn ($cell) => $cell === null ? null : ['round' => $cell['round'], 'points' => $cell['points']],
            $team['rounds'],
        );
        $played = array_column(array_filter($cells), 'points');

        return $this->team($team) + [
            'rank' => (int) $team['robot_rank'],
            'average' => $team['robot'],
            'best' => $played === [] ? null : max($played),
            'rounds' => $cells,
        ];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array{number: mixed, name: mixed}
     */
    private function team(array $team): array
    {
        return ['number' => $team['number'], 'name' => $team['name']];
    }
}
