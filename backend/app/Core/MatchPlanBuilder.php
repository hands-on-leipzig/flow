<?php

namespace App\Core;

use App\Enums\FirstProgram;
use App\Models\MatchEntry;
use App\Services\MatchRotationService;
use App\Support\MatchPlan;
use App\Support\MatchPlanSpec;

/**
 * Judging-aligned match list for one Challenge-shaped program.
 *
 * Pairing given N teams and T tables is the same for Challenge and Future 8+;
 * only MatchPlanSpec param binding differs. Derived rounds/matches/volunteer/asym
 * are read from the spec (already on PlanParameter via generator).
 *
 * Rounds 0–3 only. Afternoon rounds 4–5 (Future empty on-site slots) are not stored here.
 */
class MatchPlanBuilder
{
    public function build(MatchPlanSpec $spec): MatchPlan
    {
        $entries = $this->seedFromJudgingAlignment($spec);
        $entries = $this->rotateRounds2And3(
            $entries,
            $spec->tables,
            $spec->lanes,
        );
        $this->persist($spec, $entries);

        return new MatchPlan($spec->program, $entries);
    }

    /**
     * Rounds 0–3 from judging: start team per round, fill matches backwards,
     * 2→4 tables, TR tables from RG1 (Q2), optional empty TR match when asym.
     *
     * @return list<array<string, int>>
     */
    private function seedFromJudgingAlignment(MatchPlanSpec $spec): array
    {
        $entries = [];
        $team = 0;

        // Generate rounds 1 to 3 matching the judging round
        // Then build the test round from round 1
        // - preserve the table assignments
        // - shift matches "backwards" to fit judging round 1

        for ($round = 0; $round <= 3; $round++) {
            $team = $this->startTeamForRound($spec, $round);

            // Fill the match plan for the round starting with the last match, then going backwards
            // Start with just 2 tables. Distribution to 4 tables is done afterwards.

            for ($match = $spec->matchesPerRound; $match >= 1; $match--) {
                $team_2 = $team;
                $this->getNextTeam($spec, $team);
                $team_1 = $team;
                $this->getNextTeam($spec, $team);

                $entries[] = [
                    'round' => $round,
                    'match' => $match,
                    'table_1' => 1,
                    'table_2' => 2,
                    // Volunteer slot is team number > teams → store as 0
                    'team_1' => ($team_1 > $spec->teams) ? 0 : $team_1,
                    'team_2' => ($team_2 > $spec->teams) ? 0 : $team_2,
                ];
            }

            // With four tables move every second line to the other pair.
            $entries = $this->assignSideTables($entries, $spec->tables);
        }

        // Now, ensure that matches in TR are on the same tables as in RG1
        // This is quality measure Q2
        // Sequence of matches in TR is already correct, but the table assignment must be copied from RG1 to TR

        if (($spec->lanes % 2 === 1) && $spec->tables === 4 && $spec->judgingRounds === 4) {
            // Special case where lanes are (1,3,5), 4 tables and 4 judging rounds
            // Q2 not met, but match plan for TR works!
            // Hits 8 configurations as of Sep 3, 2025
            // TODO
        } else {
            $entries = $this->copyTablesFromRoundToRound(
                $entries,
                1,
                0,
                $spec->matchesPerRound
            );
        }

        // Special handling for asymmetric robot games
        if ($spec->asym && $spec->judgingRounds !== 4) {
            // For four tables with asymmetric robot games, we need to do more to prevent
            // the same pair of tables being used twice.
            //
            // The issue only happens if asym is true
            // This means teams = 10, 14, 18, 22 or 26 (or one team less)
            //
            // Solution is to add an empty match at tables 3+4 after lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan

            $newList = [];
            $emptyMatchInserted = false;

            foreach ($entries as $entry) {
                // For TR matches after lanes: increment match number to make room for empty match
                if ($entry['round'] === 0 && $entry['match'] > $spec->lanes) {
                    $entry['match'] += 1;
                }

                // Copy all modified or unmodified entries
                $newList[] = $entry;

                // Insert empty match right after the last lanes match in TR
                if (! $emptyMatchInserted && $entry['round'] === 0 && $entry['match'] === $spec->lanes) {
                    $newList[] = [
                        'round' => 0,
                        'match' => $spec->lanes + 1,
                        'table_1' => 3,
                        'table_2' => 4,
                        'team_1' => 0,
                        'team_2' => 0,
                    ];
                    $emptyMatchInserted = true;
                }
            }

            $entries = $newList;
        }

        return $entries;
    }

    private function startTeamForRound(MatchPlanSpec $spec, int $round): int
    {
        if ($round === 0) {
            // TR is easy: Teams starting with judging are last in TR
            return $spec->lanes;
        }

        // Challenge finale Day 2: Different team starting positions (no TR on Day 2).
        // Keep as-is for Challenge only; Future finale offsets are undecided.
        if ($spec->finale && $spec->program === FirstProgram::CHALLENGE) {
            return match ($round) {
                1 => $spec->lanes * 1, // e.g. 1 * 5 = 5
                2 => $spec->lanes * 3, // e.g. 3 * 5 = 15
                3 => $spec->lanes * 4, // e.g. 4 * 5 = 20
                default => $spec->lanes,
            };
        }

        // Normal event team starting positions
        $team = match ($spec->judgingRounds) {
            4 => $round < 3
                ? $spec->lanes * ($round + 1)
                : $spec->teams,
            5 => $round < 3
                ? $spec->lanes * ($round + 2)
                : $spec->teams,
            // Not all lanes may be filled in last judging round,
            // but that does not matter with six rounds, because robot game is aligned with judging 5
            6 => $spec->lanes * ($round + 2),
            default => $spec->teams,
        };

        // If we have an odd number of teams, start with volunteer
        if ($team === $spec->teams && $spec->needVolunteer) {
            $team = $spec->teams + 1;
        }

        return $team;
    }

    private function getNextTeam(MatchPlanSpec $spec, int &$team): void
    {
        // Get the next team with lower number
        // When 0 is reached cycle to max number
        // Include volunteer team if needed

        $team--;

        if ($team === 0) {
            $team = $spec->needVolunteer
                ? $spec->teams + 1 // Volunteer team
                : $spec->teams;
        }
    }

    /**
     * Replace match rows for this plan + program only (C and F8 can coexist).
     *
     * @param list<array<string, int>> $entries
     */
    private function persist(MatchPlanSpec $spec, array $entries): void
    {
        $planId = $spec->planId;
        $programId = $spec->program->value;

        MatchEntry::where('plan', $planId)
            ->where('first_program', $programId)
            ->delete();

        $data = array_map(function (array $entry) use ($planId, $programId) {
            return [
                'plan' => $planId,
                'first_program' => $programId,
                'round' => $entry['round'],
                'match_no' => $entry['match'],
                'table_1' => $entry['table_1'],
                'table_2' => $entry['table_2'],
                'table_1_team' => $entry['team_1'],
                'table_2_team' => $entry['team_2'],
            ];
        }, $entries);

        if ($data !== []) {
            MatchEntry::insert($data);
        }
    }

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
     * (RG1 → TR so a team keeps its table — Q2).
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
     * Rotate rounds 2 and 3. $blockSize is how the sequence is split (lanes).
     * Rotator prefers distinct opponents first, then table diversity (same for C and F8).
     *
     * @param list<array<string, int>> $entries
     * @return list<array<string, int>>
     */
    public function rotateRounds2And3(
        array $entries,
        int $tables,
        int $blockSize,
    ): array {
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
