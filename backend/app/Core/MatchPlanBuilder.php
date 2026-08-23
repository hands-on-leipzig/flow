<?php

namespace App\Core;

use App\Enums\MatchPlanObjective;
use App\Services\MatchRotationService;

/**
 * Shared match-list operations: table assignment and round 2/3 rotation.
 *
 * Pairing given N teams and T tables is the same for Challenge and Future 8+.
 * Who seeds the rounds (Challenge: judging alignment) stays in the program builder.
 */
class MatchPlanBuilder
{
    /**
     * With four tables, even-numbered matches move from tables 1–2 to 3–4.
     *
     * @param list<array<string, int>> $entries
     * @return list<array<string, int>>
     */
    public function assignSideTables(array $entries, int $tables): array
    {
        if ($tables !== 4) {
            return $entries;
        }

        foreach ($entries as &$entry) {
            if ($entry['match'] % 2 == 0) {
                $entry['table_1'] = 3;
                $entry['table_2'] = 4;
            }
        }
        unset($entry);

        return $entries;
    }

    /**
     * Copy table assignments from one round onto the same teams in another
     * (Challenge: RG1 → TR so a team keeps its table — Q2).
     *
     * @param list<array<string, int>> $entries
     * @return list<array<string, int>>
     */
    public function copyTablesFromRoundToRound(
        array $entries,
        int $fromRound,
        int $toRound,
        int $matchesPerRound
    ): array {
        for ($matchNo = 1; $matchNo <= $matchesPerRound; $matchNo++) {
            foreach ($entries as &$match) {
                if ($match['round'] === $toRound && $match['match'] === $matchNo) {
                    $team1 = $match['team_1'];
                    $team2 = $match['team_2'];

                    $m1 = collect($entries)->first(fn ($m) =>
                        $m['round'] === $fromRound && ($m['team_1'] === $team1 || $m['team_2'] === $team1)
                    );
                    if ($m1) {
                        $match['table_1'] = ($m1['team_1'] === $team1) ? $m1['table_1'] : $m1['table_2'];
                    }

                    $m2 = collect($entries)->first(fn ($m) =>
                        $m['round'] === $fromRound && ($m['team_1'] === $team2 || $m['team_2'] === $team2)
                    );
                    if ($m2) {
                        $match['table_2'] = ($m2['team_1'] === $team2) ? $m2['table_1'] : $m2['table_2'];
                    }

                    break;
                }
            }
        }
        unset($match);

        return $entries;
    }

    /**
     * Rotate rounds 2 and 3. $blockSize is how the sequence is split
     * (Challenge: j_lanes). $objective is the program goal; the service still
     * uses rematch-then-tables so current Challenge lists stay the same.
     *
     * @param list<array<string, int>> $entries
     * @return list<array<string, int>>
     */
    public function rotateRounds2And3(
        array $entries,
        int $tables,
        int $blockSize,
        MatchPlanObjective $objective
    ): array {
        unset($objective);

        $round1Seq = $this->extractRoundSequence($entries, 1);
        $round2Seq = $this->extractRoundSequence($entries, 2);
        $round3Seq = $this->extractRoundSequence($entries, 3);

        $rotationService = new MatchRotationService;
        $optimized = $rotationService->plan(
            $tables,
            $round1Seq,
            $this->splitIntoBlocks($round2Seq, $blockSize),
            $this->splitIntoBlocks($round3Seq, $blockSize)
        );

        $entries = $this->applyOptimizedSequence($entries, 2, $optimized['round2']);

        return $this->applyOptimizedSequence($entries, 3, $optimized['round3']);
    }

    /**
     * @param list<array<string, int>> $entries
     * @return list<int>
     */
    private function extractRoundSequence(array $entries, int $round): array
    {
        $roundEntries = array_filter($entries, fn ($e) => $e['round'] === $round);
        usort($roundEntries, fn ($a, $b) => $a['match'] <=> $b['match']);

        $sequence = [];
        foreach ($roundEntries as $entry) {
            $sequence[] = $entry['team_1'];
            $sequence[] = $entry['team_2'];
        }

        return $sequence;
    }

    /**
     * First / last $blockSize teams, middle the rest.
     *
     * @param list<int> $sequence
     * @return array{first: int[], middle: int[], last: int[]}
     */
    private function splitIntoBlocks(array $sequence, int $blockSize): array
    {
        $total = count($sequence);

        return [
            'first' => array_slice($sequence, 0, $blockSize),
            'last' => array_slice($sequence, $total - $blockSize, $blockSize),
            'middle' => array_slice($sequence, $blockSize, $total - 2 * $blockSize),
        ];
    }

    /**
     * @param list<array<string, int>> $entries
     * @param array{seq: int[], pairs: array<array{0:int,1:int}>, tables: array<int,int>} $optimized
     * @return list<array<string, int>>
     */
    private function applyOptimizedSequence(array $entries, int $round, array $optimized): array
    {
        $roundIndexes = [];
        foreach ($entries as $idx => $entry) {
            if ($entry['round'] === $round) {
                $roundIndexes[$idx] = $entry;
            }
        }
        uasort($roundIndexes, fn ($a, $b) => $a['match'] <=> $b['match']);

        $pairIndex = 0;
        foreach ($roundIndexes as $idx => $entry) {
            if ($pairIndex < count($optimized['pairs'])) {
                $pair = $optimized['pairs'][$pairIndex];
                $entries[$idx]['team_1'] = $pair[0];
                $entries[$idx]['team_2'] = $pair[1];
                $pairIndex++;
            }
        }

        return $entries;
    }
}
