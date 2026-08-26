<?php

namespace App\Core;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Coordinates Challenge + Future 8+ mornings when both are on (Policy A only).
 *
 * Test round runs in parallel. Rounds 1–3: all matches of one program, then the other
 * (order from f8_future_first), with f8_duration_flip_after_round between the two.
 * Judging stays per program on its own jTime; game clocks share one wall-clock timeline
 * so the other program’s round duration is visible in rTime.
 */
class GameRoundCoordinator
{
    use UsesPlanParameter;

    /** @var array{challenge: int, future: int} */
    private array $nextJudgingBlock = ['challenge' => 1, 'future' => 1];

    /** @var array{challenge: int, future: int} */
    private array $teamOffset = ['challenge' => 0, 'future' => 0];

    /** @var array{challenge: TimeCursor, future: TimeCursor} */
    private array $jEarliest;

    public function __construct(
        private readonly ChallengeGenerator $challenge,
        private readonly Future8Generator $future,
        PlanParameter $params,
    ) {
        $this->params = $params;
    }

    public function main(bool $explore = false, ?callable $afterRG1Callback = null): void
    {
        if (! (bool) $this->pp('f8_per_round', true)) {
            throw new RuntimeException(
                'Policy B (Wechsel nach jedem Match) ist noch nicht implementiert. Bitte f8_per_round aktivieren.'
            );
        }

        Log::info('GameRoundCoordinator::main', [
            'plan_id' => $this->pp('g_plan'),
            'f8_future_first' => (bool) $this->pp('f8_future_first', false),
            'explore' => $explore,
        ]);

        $this->challenge->prepareMain();
        $this->future->prepareMain();
        $this->future->syncClocksFrom($this->challenge);

        $this->jEarliest = [
            'challenge' => clone $this->challenge->jTime(),
            'future' => clone $this->future->jTime(),
        ];
        $this->nextJudgingBlock = ['challenge' => 1, 'future' => 1];
        $this->teamOffset = ['challenge' => 0, 'future' => 0];

        $futureFirst = (bool) $this->pp('f8_future_first', false);

        for ($gameRound = 0; $gameRound <= 3; $gameRound++) {
            if ($gameRound === 0) {
                $this->runJudgingUntilGameRound('challenge', 0);
                $this->runJudgingUntilGameRound('future', 0);
                $this->writeTestRoundParallel();
            } else {
                $order = $futureFirst ? ['future', 'challenge'] : ['challenge', 'future'];
                $first = true;
                foreach ($order as $key) {
                    if (! $first) {
                        $this->applyFlipPause();
                    }
                    $first = false;

                    $this->runJudgingUntilGameRound($key, $gameRound);
                    $this->syncSharedGameClock();
                    // Matches only — post-round break once after both programs finish this round.
                    $this->program($key)->writeGameRound($gameRound, false);
                    $this->syncSharedGameClock();
                }

                // Joint soft lunch / Explore after-RG1 hole: Challenge owns Explore hooks;
                // take the longer of Challenge vs Future pause on the shared clock.
                $this->applyJointPostRoundBreak($gameRound);

                if ($gameRound === 1 && $afterRG1Callback !== null) {
                    $afterRG1Callback($this->challenge->rTime());
                    $this->future->rTime()->advanceToLater($this->challenge->rTime()->current());
                }
            }
        }

        $this->finishRemainingJudging('challenge');
        $this->finishRemainingJudging('future');

        $this->challenge->finishMainAfterGames();
        $this->future->finishMainAfterGames();

        // Ceremony clock for awards / afternoon: later of both programs.
        $later = $this->challenge->cTime()->current();
        if ($this->future->cTime()->current() > $later) {
            $later = $this->future->cTime()->current();
        }
        $this->challenge->cTime()->set($later);
        $this->future->cTime()->set($later);
        $this->challenge->rTime()->advanceToLater($later);
        $this->future->rTime()->advanceToLater($later);
    }

    private function program(string $key): ChallengeGenerator|Future8Generator
    {
        return $key === 'challenge' ? $this->challenge : $this->future;
    }

    private function judgingRoundCount(string $key): int
    {
        return $this->program($key)->judgingRoundCount();
    }

    /**
     * Run judging blocks until the block that inserts $gameRound has been judged
     * (robot game for that round is written by the caller afterward).
     */
    private function runJudgingUntilGameRound(string $key, int $gameRound): void
    {
        $prog = $this->program($key);
        $max = $this->judgingRoundCount($key);

        while ($this->nextJudgingBlock[$key] <= $max) {
            $block = $this->nextJudgingBlock[$key];
            $mapped = $prog->gameRoundForJudgingBlock($block);

            $prog->runJudgingBlock(
                $block,
                $this->jEarliest[$key],
                $this->teamOffset[$key]
            );
            $this->nextJudgingBlock[$key]++;

            if ($mapped === $gameRound) {
                return;
            }
        }
    }

    private function finishRemainingJudging(string $key): void
    {
        $prog = $this->program($key);
        $max = $this->judgingRoundCount($key);

        while ($this->nextJudgingBlock[$key] <= $max) {
            $block = $this->nextJudgingBlock[$key];
            $prog->runJudgingBlock(
                $block,
                $this->jEarliest[$key],
                $this->teamOffset[$key]
            );
            $this->nextJudgingBlock[$key]++;
        }
    }

    private function writeTestRoundParallel(): void
    {
        $start = $this->challenge->rTime()->current();
        if ($this->future->rTime()->current() > $start) {
            $start = $this->future->rTime()->current();
        }

        $this->challenge->rTime()->set($start);
        $this->future->rTime()->set($start);

        $this->challenge->writeGameRound(0);
        $chEnd = $this->challenge->rTime()->current();

        $this->future->rTime()->set($start);
        $this->future->writeGameRound(0);
        $f8End = $this->future->rTime()->current();

        $end = $chEnd > $f8End ? $chEnd : $f8End;
        $this->challenge->rTime()->set($end);
        $this->future->rTime()->set($end);
    }

    private function syncSharedGameClock(): void
    {
        $ch = $this->challenge->rTime()->current();
        $f8 = $this->future->rTime()->current();
        $later = $ch > $f8 ? $ch : $f8;
        $this->challenge->rTime()->set($later);
        $this->future->rTime()->set($later);
    }

    /**
     * Pause on the shared game timeline when Policy A flips from one program’s
     * full round matches to the other’s (f8_duration_flip_after_round).
     */
    private function applyFlipPause(): void
    {
        $minutes = (int) $this->pp('f8_duration_flip_after_round', 0);
        if ($minutes <= 0) {
            return;
        }

        $this->syncSharedGameClock();
        $this->challenge->rTime()->addMinutes($minutes);
        $this->future->rTime()->set($this->challenge->rTime()->current());
    }

    /**
     * One shared pause after both programs' matches for this round.
     * Challenge applies first (sets integrated Explore rg1End / afternoon hole);
     * Future applies from the same match-end so the longer lunch/break wins.
     */
    private function applyJointPostRoundBreak(int $gameRound): void
    {
        $matchEnd = $this->challenge->rTime()->current();
        if ($this->future->rTime()->current() > $matchEnd) {
            $matchEnd = $this->future->rTime()->current();
        }

        $this->challenge->rTime()->set($matchEnd);
        $this->challenge->applyPostRoundBreak($gameRound);
        $chEnd = $this->challenge->rTime()->current();

        $this->future->rTime()->set($matchEnd);
        $this->future->applyPostRoundBreak($gameRound);
        $f8End = $this->future->rTime()->current();

        $end = $chEnd > $f8End ? $chEnd : $f8End;
        $this->challenge->rTime()->set($end);
        $this->future->rTime()->set($end);
    }
}
