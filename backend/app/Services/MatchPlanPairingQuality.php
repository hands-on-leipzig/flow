<?php

namespace App\Services;

/**
 * Pure pairing quality for catalog (and reusable) match rows.
 * No plan_id, no activities, no q_plan writes.
 *
 * @phpstan-type MatchRow array{
 *   round: int,
 *   match_no?: int,
 *   table_1: int,
 *   table_2: int,
 *   table_1_team: int,
 *   table_2_team: int
 * }
 */
class MatchPlanPairingQuality
{
    /**
     * @param  list<MatchRow>  $matches
     * @return array{
     *   scoring_rounds: list<int>,
     *   match_summary: list<array<string, mixed>>,
     *   meeting_matrix: list<list<string>>,
     *   q2_ok_count: int,
     *   q3_ok_count: int,
     *   q4_ok_count: int,
     *   teams: int,
     *   tables: int
     * }
     */
    public function evaluate(array $matches, int $teams, int $tablesAvailable): array
    {
        $scoringRounds = $this->scoringRounds($matches);
        $byRound = [];
        foreach ($matches as $match) {
            $round = (int) $match['round'];
            $byRound[$round][] = $match;
        }

        $summary = [];
        $q2Ok = 0;
        $q3Ok = 0;
        $q4Ok = 0;
        $targetTables = ($tablesAvailable === 2) ? 2 : 3;
        $targetOpponents = count($scoringRounds);

        for ($team = 1; $team <= $teams; $team++) {
            $entry = ['team' => $team];

            $trMatch = $this->findTeamMatch($byRound[0] ?? [], $team);
            $trTable = $trMatch === null ? null : $this->tableForTeam($trMatch, $team);
            $entry['tr_table'] = $trTable;

            $tablesPlayed = [];
            $opponents = [];

            foreach ($scoringRounds as $r) {
                $match = $this->findTeamMatch($byRound[$r] ?? [], $team);
                if ($match === null) {
                    $entry["r{$r}_table"] = null;
                    $entry["r{$r}_opponent"] = null;
                    continue;
                }
                $table = $this->tableForTeam($match, $team);
                $opponent = $this->opponentForTeam($match, $team);
                $entry["r{$r}_table"] = $table;
                $entry["r{$r}_opponent"] = $opponent;
                if ($table !== null) {
                    $tablesPlayed[] = $table;
                }
                if ($opponent !== null) {
                    $opponents[] = $opponent;
                }
            }

            $distinctTables = count(array_unique($tablesPlayed));
            $distinctOpponents = count(array_unique($opponents));
            $entry['tables'] = $distinctTables;
            $entry['teams'] = $distinctOpponents;

            $q2Pass = ($tablesAvailable === 2 && $distinctTables === 2)
                || ($tablesAvailable === 4 && $distinctTables === 3);
            $q3Pass = $targetOpponents > 0 && $distinctOpponents === $targetOpponents;
            $q4Pass = $trTable !== null
                && isset($entry['r1_table'])
                && $entry['r1_table'] !== null
                && (int) $trTable === (int) $entry['r1_table'];

            $entry['q2_ok'] = $q2Pass;
            $entry['q3_ok'] = $q3Pass;
            $entry['q4_ok'] = $q4Pass;
            $entry['q2_target'] = $targetTables;
            $entry['q3_target'] = $targetOpponents;

            if ($q2Pass) {
                $q2Ok++;
            }
            if ($q3Pass) {
                $q3Ok++;
            }
            if ($q4Pass) {
                $q4Ok++;
            }

            $summary[] = $entry;
        }

        return [
            'scoring_rounds' => $scoringRounds,
            'match_summary' => $summary,
            'meeting_matrix' => $this->meetingMatrix($matches, $teams, $scoringRounds),
            'q2_ok_count' => $q2Ok,
            'q3_ok_count' => $q3Ok,
            'q4_ok_count' => $q4Ok,
            'teams' => $teams,
            'tables' => $tablesAvailable,
        ];
    }

    /**
     * @param  list<MatchRow>  $matches
     * @return list<int>
     */
    private function scoringRounds(array $matches): array
    {
        $rounds = [];
        foreach ($matches as $match) {
            $round = (int) $match['round'];
            if ($round >= 1) {
                $rounds[$round] = $round;
            }
        }
        $list = array_values($rounds);
        sort($list);

        return $list;
    }

    /**
     * @param  list<MatchRow>  $roundMatches
     * @return MatchRow|null
     */
    private function findTeamMatch(array $roundMatches, int $team): ?array
    {
        foreach ($roundMatches as $match) {
            if ((int) $match['table_1_team'] === $team || (int) $match['table_2_team'] === $team) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  MatchRow  $match
     */
    private function tableForTeam(array $match, int $team): ?int
    {
        if ((int) $match['table_1_team'] === $team) {
            return (int) $match['table_1'];
        }
        if ((int) $match['table_2_team'] === $team) {
            return (int) $match['table_2'];
        }

        return null;
    }

    /**
     * @param  MatchRow  $match
     */
    private function opponentForTeam(array $match, int $team): ?int
    {
        if ((int) $match['table_1_team'] === $team) {
            return (int) $match['table_2_team'];
        }
        if ((int) $match['table_2_team'] === $team) {
            return (int) $match['table_1_team'];
        }

        return null;
    }

    /**
     * @param  list<MatchRow>  $matches
     * @param  list<int>  $scoringRounds
     * @return list<list<string>>  1-indexed via padding: matrix[teamA-1][teamB-1]
     */
    private function meetingMatrix(array $matches, int $teams, array $scoringRounds): array
    {
        $cells = [];
        for ($a = 1; $a <= $teams; $a++) {
            for ($b = 1; $b <= $teams; $b++) {
                $cells[$a][$b] = [];
            }
        }

        $scoringSet = array_fill_keys($scoringRounds, true);

        foreach ($matches as $match) {
            $round = (int) $match['round'];
            if (! isset($scoringSet[$round])) {
                continue;
            }
            $t1 = (int) $match['table_1_team'];
            $t2 = (int) $match['table_2_team'];
            if ($t1 < 1 || $t2 < 1 || $t1 > $teams || $t2 > $teams) {
                continue;
            }
            $cells[$t1][$t2][] = $round;
            $cells[$t2][$t1][] = $round;
        }

        $matrix = [];
        for ($a = 1; $a <= $teams; $a++) {
            $row = [];
            for ($b = 1; $b <= $teams; $b++) {
                if ($a === $b) {
                    $row[] = '';
                    continue;
                }
                $rounds = array_values(array_unique($cells[$a][$b]));
                sort($rounds);
                $row[] = implode(',', $rounds);
            }
            $matrix[] = $row;
        }

        return $matrix;
    }
}
