<?php

namespace App\Scor;

/**
 * Released results for visitors.
 * Spiel shows points and rank. Awards show only the podium, nominees and trophy holders.
 * No jury points, no jury ranks, no overall ranking.
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
                if ((string) $row['category'] !== $category || !isset($teams[$id])) {
                    continue;
                }
                $nominees[] = $this->team($teams[$id]) + ['trophy' => ($trophyHolder[$category] ?? null) === $id];
            }
            usort($nominees, fn (array $a, array $b) => (int) $a['number'] <=> (int) $b['number']);
            $categories[] = ['category' => $category, 'nominees' => $nominees];
        }

        $coaching = $awards['coaching'] ?? null;

        $game = array_values($teams);
        usort($game, function (array $a, array $b): int {
            if ($a['robot_rank'] !== $b['robot_rank']) {
                return $a['robot_rank'] <=> $b['robot_rank'];
            }

            return (int) $a['number'] <=> (int) $b['number'];
        });

        return [
            'event' => $results['event'],
            'rounds' => $results['rounds'],
            'game' => array_map(fn (array $team) => $this->team($team) + [
                'rank' => (int) $team['robot_rank'],
                'average' => $team['robot'],
                'rounds' => array_map(
                    fn ($cell) => $cell === null ? null : ['round' => $cell['round'], 'points' => $cell['points']],
                    $team['rounds'],
                ),
            ], $game),
            'podium' => $podium,
            'categories' => $categories,
            'coaching' => $coaching && isset($teams[(int) $coaching]) ? $this->team($teams[(int) $coaching]) : null,
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
