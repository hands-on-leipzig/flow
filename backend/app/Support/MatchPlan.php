<?php

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * Robot-game match list for one program on one plan.
 *
 * Not a clock: no rTime, no activities. One of these per Challenge-shaped program.
 *
 * @phpstan-type MatchEntry array{
 *     round: int,
 *     match: int,
 *     table_1: int,
 *     table_2: int,
 *     team_1: int,
 *     team_2: int
 * }
 */
class MatchPlan
{
    /**
     * @param list<MatchEntry> $entries
     */
    public function __construct(
        public FirstProgram $program,
        public array $entries,
    ) {
    }

    /**
     * Matches of one round, in match-number order.
     *
     * @return list<MatchEntry>
     */
    public function entriesForRound(int $round): array
    {
        $roundEntries = array_values(array_filter(
            $this->entries,
            fn (array $entry) => $entry['round'] === $round
        ));
        usort($roundEntries, fn (array $a, array $b) => $a['match'] <=> $b['match']);

        return $roundEntries;
    }

    /** Highest catalog/live round number (TR = 0). */
    public function maxRound(): int
    {
        if ($this->entries === []) {
            return -1;
        }

        return (int) max(array_column($this->entries, 'round'));
    }

    /** Judging blocks = max(round) + 1, including TR. */
    public function judgingRoundCount(): int
    {
        return $this->maxRound() + 1;
    }
}
