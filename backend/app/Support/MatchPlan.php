<?php

namespace App\Support;

use App\Enums\FirstProgram;
use App\Enums\MatchPlanObjective;

/**
 * Robot-game match list for one program on one plan.
 *
 * Not a clock: no rTime, no activities. One of these per program later
 * (Challenge now; Future 8+ when that program is generated).
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
        public MatchPlanObjective $objective,
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
}
