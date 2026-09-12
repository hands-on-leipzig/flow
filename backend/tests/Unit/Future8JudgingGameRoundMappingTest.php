<?php

namespace Tests\Unit;

use App\Core\Future8Generator;
use PHPUnit\Framework\TestCase;

class Future8JudgingGameRoundMappingTest extends TestCase
{
    public function test_one_to_one_including_tr_for_four_to_six_judging_rounds(): void
    {
        foreach ([4, 5, 6] as $n) {
            for ($block = 1; $block <= $n; $block++) {
                $this->assertSame(
                    $block - 1,
                    Future8Generator::catalogGameRoundForJudgingBlock($block, $n),
                    "N={$n} block {$block}"
                );
            }
            $this->assertNull(Future8Generator::catalogGameRoundForJudgingBlock(0, $n));
            $this->assertNull(Future8Generator::catalogGameRoundForJudgingBlock($n + 1, $n));
        }
    }

    public function test_after_rg1_is_only_judging_block_two(): void
    {
        foreach ([4, 5, 6] as $n) {
            $rg1Blocks = [];
            for ($block = 1; $block <= $n; $block++) {
                if (Future8Generator::catalogGameRoundForJudgingBlock($block, $n) === 1) {
                    $rg1Blocks[] = $block;
                }
            }
            $this->assertSame([2], $rg1Blocks, "N={$n}");
        }
    }

    public function test_protected_index_stays_staggered_after_all_teams_have_a_slot(): void
    {
        // 8 teams, 4 lanes, 4 matches/round, N=4: compress used to set rMB=0 from block 2.
        for ($block = 1; $block <= 4; $block++) {
            $this->assertSame(
                2,
                Future8Generator::catalogProtectedMatchIndex($block, 4, 8, 4, 4),
                "block {$block}"
            );
        }
    }

    public function test_protected_index_partial_last_judging_block(): void
    {
        $this->assertSame(4, Future8Generator::catalogProtectedMatchIndex(1, 6, 11, 4, 6));
        $this->assertSame(3, Future8Generator::catalogProtectedMatchIndex(6, 6, 11, 4, 6));
    }

    public function test_two_lanes_next_judging_waits_for_first_two_matches(): void
    {
        $this->assertSame(2, Future8Generator::catalogEarlyMatchesForNextJudging(2, 4));
        $this->assertSame(2, Future8Generator::catalogEarlyMatchesForNextJudging(2, 2));
        $this->assertSame(1, Future8Generator::catalogEarlyMatchesForNextJudging(2, 1));
        $this->assertSame(2, Future8Generator::catalogEarlyMatchesForNextJudging(4, 4));
        $this->assertSame(3, Future8Generator::catalogEarlyMatchesForNextJudging(5, 6));
    }
}
