<?php
// app/Services/MatchRotationService.php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic rotation planner for rounds 2 and 3.
 *
 * Assumptions:
 * - Even number of teams (no BYE).
 * - Exactly 3 rounds total; Round 1 sequence is fixed and provided as-is.
 * - For Round 2 and Round 3, you provide three disjoint blocks {First, Middle, Last}
 *   as arrays of concrete team IDs (order is the "delivered" order).
 * - Matches are formed by consecutive teams in the concatenated sequence:
 *     Seq = First ⧺ Middle ⧺ Last → (1,2), (3,4), (5,6), ...
 * - Table mapping:
 *     r_tables = 2 → tables: 1,2,1,2, ...
 *     r_tables = 4 → windows of 2 matches: (1+2)→1,2 ; (3+4)→3,4 ; (5+6)→1,2 ; (7+8)→3,4 ; ...
 * - Objective (lexicographic):
 *     1) Avoid rematches (maximize distinct opponents over 3 rounds)
 *     2) Maximize table diversity (2-tables: target=2; 4-tables: target=3)
 *     3) Minimize deviation from delivered block order (tie-break)
 */
class MatchRotationService
{
    /** @var int */
    private int $rTables;

    /** @var array<int, array<int, bool>> teamId => set(opponents) seen so far */
    private array $opponentsSeen = [];

    /** @var array<int, array<int, bool>> teamId => set(tables) seen so far (1-based table numbers) */
    private array $tablesSeen = [];

    /** @var array<int, int> original index per team within its delivered block (for tie-breaking) */
    private array $deliveredIndex = [];

    /**
     * Entry point: compute Round 2 and Round 3 sequences from delivered blocks, given Round 1 baseline.
     *
     * @param int $rTables  Either 2 or 4
     * @param array<int> $round1Seq  Full delivered sequence for Round 1 (concatenated First⧺Middle⧺Last)
     * @param array{first: int[], middle: int[], last: int[]} $round2Blocks
     * @param array{first: int[], middle: int[], last: int[]} $round3Blocks
     * @return array{
     *   round2: array{seq: int[], pairs: array<array{0:int,1:int}>, tables: array<int,int>},
     *   round3: array{seq: int[], pairs: array<array{0:int,1:int}>, tables: array<int,int>}
     * }
     */
    public function plan(int $rTables,
                         array $round1Seq,
                         array $round2Blocks,
                         array $round3Blocks): array
    {
        if (!in_array($rTables, [2,4], true)) {
            throw new \InvalidArgumentException('r_tables must be 2 or 4.');
        }
        $this->rTables = $rTables;

        $teams = $round1Seq;
        $this->assertEven(count($teams));
        $this->assertDisjointAndCover($round2Blocks, $teams, 'Round2');
        $this->assertDisjointAndCover($round3Blocks, $teams, 'Round3');

        // Initialize history from Round 1
        $this->initHistory($teams);
        $r1Pairs = $this->buildPairs($round1Seq);
        $r1Tables = $this->assignTablesForPairs(count($r1Pairs));
        $this->applyRoundToHistory($r1Pairs, $r1Tables);

        // Round 2 rotation
        $this->indexDelivered($round2Blocks);
        $r2Seq = $this->rotateOneRound($round2Blocks);
        $r2Pairs = $this->buildPairs($r2Seq);
        $r2Tables = $this->assignTablesForPairs(count($r2Pairs));
        $this->applyRoundToHistory($r2Pairs, $r2Tables);

        // Round 3 rotation
        $this->indexDelivered($round3Blocks);
        $r3Seq = $this->rotateOneRound($round3Blocks);
        $r3Pairs = $this->buildPairs($r3Seq);
        $r3Tables = $this->assignTablesForPairs(count($r3Pairs));
        $this->applyRoundToHistory($r3Pairs, $r3Tables);

        return [
            'round2' => [
                'seq'    => $r2Seq,
                'pairs'  => $r2Pairs,
                'tables' => $this->tablesPerTeamFromPairs($r2Pairs, $r2Tables),
            ],
            'round3' => [
                'seq'    => $r3Seq,
                'pairs'  => $r3Pairs,
                'tables' => $this->tablesPerTeamFromPairs($r3Pairs, $r3Tables),
            ],
        ];
    }

    // ---------- Core rotation for one round ----------

    /**
     * Rotate within blocks to minimize rematches and push table diversity.
     * Deterministic: stable keys and tie-breakers.
     *
     * @param array{first: int[], middle: int[], last: int[]} $blocks
     * @return int[] concatenated sequence First⧺Middle⧺Last
     */
    private function rotateOneRound(array $blocks): array
    {
        // 1) Anti-rematch ordering inside each block (deterministic greedy)
        $first  = $this->orderBlockAntiRematch($blocks['first']);
        $middle = $this->orderBlockAntiRematch($blocks['middle']);
        $last   = $this->orderBlockAntiRematch($blocks['last']);

        // 2) Handle odd-length boundaries by choosing boundary pair to avoid rematch
        // First ↔ Middle
        if ((count($first) % 2) === 1) {
            $this->fixBoundaryPair($first, $middle);
        }
        // Middle ↔ Last
        if ((count($middle) % 2) === 1) {
            $this->fixBoundaryPair($middle, $last);
        }

        $seq = array_merge($first, $middle, $last);

        // 3) Table diversity improvement via local swaps within blocks (keep rematch-free)
        $seq = $this->improveTablesByLocalSwaps($seq, [
            'firstLen'  => count($first),
            'middleLen' => count($middle),
            'lastLen'   => count($last),
        ]);

        return $seq;
    }

    /**
     * Greedy ordering inside a block to avoid rematches on adjacent pairs.
     * Keep deviation from delivered order minimal (tie-break).
     *
     * @param int[] $block
     * @return int[]
     */
    private function orderBlockAntiRematch(array $block): array
    {
        $candidates = array_values($block);
        $n = count($candidates);
        
        // For small blocks (≤6 teams = 3 pairs), try to find optimal pairing
        if ($n >= 4 && $n <= 8) {
            $bestOrdering = $this->findBestPairingForSmallBlock($candidates);
            if ($bestOrdering !== null) {
                return $bestOrdering;
            }
        }

        // Fall back to greedy approach for larger blocks or if optimal fails
        return $this->greedyBlockOrdering($candidates);
    }

    /**
     * Find optimal pairing for small blocks by trying multiple arrangements.
     * Uses a deterministic approach with rotations and swaps.
     * 
     * @param int[] $candidates
     * @return int[]|null
     */
    private function findBestPairingForSmallBlock(array $candidates): ?array
    {
        $n = count($candidates);
        $bestOrdering = null;
        $bestScore = PHP_INT_MAX;
        
        // Try different starting arrangements deterministically
        // For n=6, try ~50 different arrangements
        $maxAttempts = min(100, $n * $n);
        
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $test = $candidates;
            
            // Apply deterministic transformations based on attempt number
            if ($attempt > 0) {
                // Rotate elements
                $rotations = $attempt % $n;
                for ($r = 0; $r < $rotations; $r++) {
                    $first = array_shift($test);
                    $test[] = $first;
                }
                
                // Apply swaps based on attempt number
                $swapPattern = (int)($attempt / $n);
                if ($swapPattern > 0 && $swapPattern < $n - 1) {
                    $temp = $test[$swapPattern];
                    $test[$swapPattern] = $test[$swapPattern + 1];
                    $test[$swapPattern + 1] = $temp;
                }
            }
            
            // Count rematches in this arrangement
            $rematches = 0;
            for ($i = 0; $i + 1 < $n; $i += 2) {
                if ($this->isRematch($test[$i], $test[$i+1])) {
                    $rematches++;
                }
            }
            
            // Score: prioritize fewer rematches
            $score = $rematches * 1000;
            
            // Add table diversity as secondary criterion
            $tableScore = 0;
            $pairs = [];
            for ($i = 0; $i + 1 < $n; $i += 2) {
                $pairs[] = [$test[$i], $test[$i+1]];
            }
            $tables = $this->assignTablesForPairs(count($pairs));
            foreach ($pairs as $idx => [$a, $b]) {
                $matchTables = $tables[$idx + 1];
                if (!$this->isNewTableForTeam($a, $matchTables['table_1'])) {
                    $tableScore += 1;
                }
                if (!$this->isNewTableForTeam($b, $matchTables['table_2'])) {
                    $tableScore += 1;
                }
            }
            $score += $tableScore;
            
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestOrdering = $test;
                
                // If we found zero rematches, we're done
                if ($rematches === 0) {
                    break;
                }
            }
        }
        
        return $bestOrdering;
    }

    /**
     * Original greedy ordering algorithm.
     */
    private function greedyBlockOrdering(array $candidates): array
    {
        // Risk measure: how many potential neighbors (within block) are already opponents.
        $risk = [];
        $setBlock = array_fill_keys($candidates, true);
        foreach ($candidates as $u) {
            $seen = $this->opponentsSeen[$u] ?? [];
            // intersect(seen, block)
            $conflicts = 0;
            foreach ($seen as $v => $true) {
                if (isset($setBlock[$v])) $conflicts++;
            }
            $risk[$u] = $conflicts;
        }

        // Sort by (higher risk first, larger missing table set first, smaller delivered index, smaller team id)
        usort($candidates, function(int $a, int $b) use ($risk) {
            $ra = $risk[$a] ?? 0;
            $rb = $risk[$b] ?? 0;
            if ($ra !== $rb) return $rb <=> $ra;

            $ta = $this->missingTableScore($a);
            $tb = $this->missingTableScore($b);
            if ($ta !== $tb) return $tb <=> $ta;

            $da = $this->deliveredIndex[$a] ?? PHP_INT_MAX;
            $db = $this->deliveredIndex[$b] ?? PHP_INT_MAX;
            if ($da !== $db) return $da <=> $db;

            return $a <=> $b;
        });

        // Build sequence ensuring adjacent pairs are (as much as possible) non-rematch
        $ordered = [];
        foreach ($candidates as $u) {
            $ordered[] = $u;
        }

        // Local pass to reduce rematches with adjacent neighbors by swapping neighbors when safe
        for ($i = 0; $i + 1 < count($ordered); $i += 2) {
            $a = $ordered[$i];
            $b = $ordered[$i+1];
            if ($this->isRematch($a, $b)) {
                // try swap with next pair's first/second if exists
                if ($i + 3 < count($ordered)) {
                    // try (a, ordered[i+2]) and (ordered[i+1], ordered[i+3])
                    $c = $ordered[$i+2];
                    $d = $ordered[$i+3];
                    $curBad = (int)$this->isRematch($a,$b) + (int)$this->isRematch($c,$d);
                    $opt1Bad = (int)$this->isRematch($a,$c) + (int)$this->isRematch($b,$d);
                    $opt2Bad = (int)$this->isRematch($a,$d) + (int)$this->isRematch($b,$c);
                    if ($opt1Bad < $curBad && $this->breaksLessDelivered([$b,$c], [$ordered[$i+1],$ordered[$i+2]])) {
                        // use (a,c),(b,d) → swap b with c
                        $ordered[$i+1] = $c;
                        $ordered[$i+2] = $b;
                    } elseif ($opt2Bad < $curBad && $this->breaksLessDelivered([$b,$d], [$ordered[$i+1],$ordered[$i+3]])) {
                        // use (a,d),(b,c) → rotate b,c,d
                        $ordered[$i+1] = $d;
                        $ordered[$i+3] = $b;
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * If left block has odd length, ensure boundary pair (last of left, first of right)
     * is not a rematch. Try minimal local swaps in the two blocks to achieve this.
     *
     * @param int[] &$left
     * @param int[] &$right
     * @return void
     */
    private function fixBoundaryPair(array &$left, array &$right): void
    {
        if (empty($left) || empty($right)) return;
        $L = count($left);
        $R = count($right);
        $u = $left[$L - 1];
        $v = $right[0];

        if (!$this->isRematch($u, $v)) {
            return; // already fine
        }

        // Try swap last two of left
        if ($L >= 2) {
            $u2 = $left[$L - 2];
            // swap u2 <-> u and test boundary
            if (!$this->isRematch($u2, $v)) {
                [$left[$L - 2], $left[$L - 1]] = [$left[$L - 1], $left[$L - 2]];
                return;
            }
        }

        // Try swap first two of right
        if ($R >= 2) {
            $v2 = $right[1];
            // swap v2 <-> v and test boundary
            if (!$this->isRematch($u, $v2)) {
                [$right[0], $right[1]] = [$right[1], $right[0]];
                return;
            }
        }

        // Try minimal rotation across both sides: (u2 with v2) style
        if ($L >= 2 && $R >= 2) {
            $u2 = $left[$L - 2];
            $v2 = $right[1];
            // Two options: make boundary (u2,v) or (u,v2)
            $bad_u2v = (int)$this->isRematch($u2, $v);
            $bad_uv2 = (int)$this->isRematch($u, $v2);
            if ($bad_u2v < $bad_uv2) {
                // swap u2<->u
                [$left[$L - 2], $left[$L - 1]] = [$left[$L - 1], $left[$L - 2]];
            } else {
                // swap v2<->v
                [$right[0], $right[1]] = [$right[1], $right[0]];
            }
        }
        // If still a rematch, we accept it here to avoid cascading larger changes.
    }

    /**
     * Improve table diversity via in-block adjacent swaps that do not introduce rematches.
     *
     * @param int[] $seq  Concatenated (first ⧺ middle ⧺ last)
     * @param array{firstLen:int,middleLen:int,lastLen:int} $meta
     * @return int[] improved sequence (still same membership per block)
     */
    private function improveTablesByLocalSwaps(array $seq, array $meta): array
    {
        $pairs = $this->buildPairs($seq);
        $tables = $this->assignTablesForPairs(count($pairs));

        // Helper to get table for a team in this round
        $tableOf = function(int $team) use ($pairs, $tables): int {
            foreach ($pairs as $i => $p) {
                $matchTables = $tables[$i + 1];
                if ($p[0] === $team) {
                    return $matchTables['table_1'];
                }
                if ($p[1] === $team) {
                    return $matchTables['table_2'];
                }
            }
            return 0;
        };

        // Single pass per block: try adjacent swaps (i,i+1) that keep pairs rematch-free and increase novelty
        $offset = 0;
        foreach (['firstLen','middleLen','lastLen'] as $key) {
            $len = $meta[$key];
            if ($len === 0) { continue; }

            // Indices in $seq for this block: [$offset, $offset+$len-1]
            $start = $offset;
            $end   = $offset + $len - 1;

            for ($i = $start; $i < $end; $i++) {
                // Swap neighbors only if it (a) does not create rematch in the relevant pairs,
                // and (b) increases table novelty sum for the two teams.
                $a = $seq[$i];
                $b = $seq[$i+1];

                // Current local pairs affected: depends on position parity.
                // For safety, check all pairs touching indices i-1..i+2
                $beforePairs = $this->buildPairs($seq);
                $beforeTables = $this->assignTablesForPairs(count($beforePairs));

                $beforeRematch = $this->countRematchesInNeighborhood($seq, $i);
                $novA_before = $this->isNewTableForTeam($a, $tableOf($a)) ? 1 : 0;
                $novB_before = $this->isNewTableForTeam($b, $tableOf($b)) ? 1 : 0;
                $nov_before = $novA_before + $novB_before;

                // Try swap
                $seq[$i] = $b; $seq[$i+1] = $a;

                $afterPairs = $this->buildPairs($seq);
                $afterTables = $this->assignTablesForPairs(count($afterPairs));
                $afterRematch = $this->countRematchesInNeighborhood($seq, $i);

                $tableOfAfter = function(int $team) use ($afterPairs, $afterTables): int {
                    foreach ($afterPairs as $j => $p) {
                        $matchTables = $afterTables[$j + 1];
                        if ($p[0] === $team) {
                            return $matchTables['table_1'];
                        }
                        if ($p[1] === $team) {
                            return $matchTables['table_2'];
                        }
                    }
                    return 0;
                };

                $novA_after = $this->isNewTableForTeam($a, $tableOfAfter($a)) ? 1 : 0;
                $novB_after = $this->isNewTableForTeam($b, $tableOfAfter($b)) ? 1 : 0;
                $nov_after = $novA_after + $novB_after;

                // Accept swap only if it does not increase rematches and improves novelty
                if ($afterRematch > $beforeRematch || $nov_after <= $nov_before) {
                    // rollback
                    $seq[$i] = $a; $seq[$i+1] = $b;
                }
            }

            $offset += $len;
        }

        return $seq;
    }

    // ---------- Helpers: scoring, history, pairs, tables ----------

    /** @param int[] $seq @return array<array{0:int,1:int}> */
    private function buildPairs(array $seq): array
    {
        $n = count($seq);
        $this->assertEven($n);
        $pairs = [];
        for ($i = 0; $i < $n; $i += 2) {
            $pairs[] = [$seq[$i], $seq[$i+1]];
        }
        return $pairs;
    }

    /** @param array<array{0:int,1:int}> $pairs @return array<int,array{table_1:int,table_2:int}> matchIndex(1-based) => [table_1, table_2] */
    private function assignTablesForPairs(int $numPairs): array
    {
        $tables = [];
        for ($m = 1; $m <= $numPairs; $m++) {
            $tables[$m] = $this->tablesForMatchIndex($m);
        }
        return $tables;
    }

    /** @return array{table_1:int,table_2:int} */
    private function tablesForMatchIndex(int $matchIndex): array
    {
        if ($this->rTables === 2) {
            // Always tables 1 and 2
            return ['table_1' => 1, 'table_2' => 2];
        }
        // r_tables=4: match-based alternating
        // Odd matches (1,3,5,...) use tables 1,2
        // Even matches (2,4,6,...) use tables 3,4
        if ($matchIndex % 2 === 1) {
            return ['table_1' => 1, 'table_2' => 2];
        } else {
            return ['table_1' => 3, 'table_2' => 4];
        }
    }

    /** Update opponentsSeen & tablesSeen with one round outcome. */
    private function applyRoundToHistory(array $pairs, array $tables): void
    {
        foreach ($pairs as $i => [$a, $b]) {
            $matchTables = $tables[$i + 1];
            $this->opponentsSeen[$a][$b] = true;
            $this->opponentsSeen[$b][$a] = true;
            // Team A plays on table_1, Team B plays on table_2
            $this->tablesSeen[$a][$matchTables['table_1']] = true;
            $this->tablesSeen[$b][$matchTables['table_2']] = true;
        }
    }

    private function isRematch(int $u, int $v): bool
    {
        return isset($this->opponentsSeen[$u][$v]) || isset($this->opponentsSeen[$v][$u]);
    }

    private function isNewTableForTeam(int $u, int $table): bool
    {
        return !isset($this->tablesSeen[$u][$table]);
    }

    /** Missing table "strength": higher means stronger need. */
    private function missingTableScore(int $u): int
    {
        $seen = $this->tablesSeen[$u] ?? [];
        if ($this->rTables === 2) {
            return (count($seen) < 2) ? 1 : 0;
        }
        // r_tables = 4, target = 3 distinct after all 3 rounds
        // Early rounds: prefer teams with fewer seen tables.
        $k = count($seen);
        if ($k <= 1) return 2; // strong push
        if ($k === 2) return 1; // mild push
        return 0;
    }

    /** Count rematches touching indices around i (local neighborhood check). */
    private function countRematchesInNeighborhood(array $seq, int $i): int
    {
        $pairs = $this->buildPairs($seq);
        $bad = 0;
        foreach ($pairs as [$a,$b]) {
            if ($this->isRematch($a,$b)) $bad++;
        }
        return $bad;
    }

    /** Count total rematches in a sequence. */
    private function countTotalRematches(array $seq): int
    {
        $pairs = $this->buildPairs($seq);
        $bad = 0;
        foreach ($pairs as [$a,$b]) {
            if ($this->isRematch($a,$b)) $bad++;
        }
        return $bad;
    }

    /** Delivered-order tie-break: prefer changes that keep local order closer to delivered. */
    private function breaksLessDelivered(array $candidate, array $original): bool
    {
        $deltaCand = 0;
        foreach ($candidate as $idx => $team) {
            $deltaCand += abs(($this->deliveredIndex[$team] ?? $idx) - $idx);
        }
        $deltaOrig = 0;
        foreach ($original as $idx => $team) {
            $deltaOrig += abs(($this->deliveredIndex[$team] ?? $idx) - $idx);
        }
        return $deltaCand <= $deltaOrig;
    }

    /** @param array<array{0:int,1:int}> $pairs @param array<int,array{table_1:int,table_2:int}> $tables @return array<int,int> team => table */
    private function tablesPerTeamFromPairs(array $pairs, array $tables): array
    {
        $res = [];
        foreach ($pairs as $i => [$a,$b]) {
            $matchTables = $tables[$i + 1];
            $res[$a] = $matchTables['table_1'];
            $res[$b] = $matchTables['table_2'];
        }
        return $res;
    }

    private function initHistory(array $teams): void
    {
        $this->opponentsSeen = [];
        $this->tablesSeen = [];
        foreach ($teams as $u) {
            $this->opponentsSeen[$u] = [];
            $this->tablesSeen[$u] = [];
        }
    }

    /** Record delivered index per team inside its block to minimize deviations. */
    private function indexDelivered(array $blocks): void
    {
        $this->deliveredIndex = [];
        foreach (['first','middle','last'] as $key) {
            foreach ($blocks[$key] as $idx => $team) {
                // smaller deliveredIndex → higher priority to keep near this position
                $this->deliveredIndex[$team] = $idx;
            }
        }
    }

    // ---------- Assertions & validation ----------

    private function assertEven(int $n): void
    {
        if ($n % 2 !== 0) {
            throw new \InvalidArgumentException('Even number of teams is required (no BYE).');
        }
    }

    /**
     * Ensure blocks are disjoint and cover exactly the provided team set.
     *
     * @param array{first:int[], middle:int[], last:int[]} $blocks
     * @param int[] $teams
     * @param string $label
     */
    private function assertDisjointAndCover(array $blocks, array $teams, string $label): void
    {
        $union = array_merge($blocks['first'], $blocks['middle'], $blocks['last']);
        sort($union);
        $sortedTeams = $teams;
        sort($sortedTeams);
        if ($union !== $sortedTeams) {
            throw new \InvalidArgumentException($label . ': Blocks must be disjoint and cover all teams exactly.');
        }
    }
}

