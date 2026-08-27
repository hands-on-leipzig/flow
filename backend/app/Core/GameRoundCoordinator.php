<?php

namespace App\Core;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use DateTime;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates Challenge + Future 8+ mornings when both are on.
 *
 * Policy A (f8_per_round): full round then flip; f8_duration_flip_after_round between.
 * Policy B (!f8_per_round): zip/drain per match/wave; no flip pause; no robot check.
 * Test round stays parallel for both policies.
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
        $policyA = (bool) $this->pp('f8_per_round', true);

        Log::info('GameRoundCoordinator::main', [
            'plan_id' => $this->pp('g_plan'),
            'policy' => $policyA ? 'A' : 'B',
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
            } elseif ($policyA) {
                $this->runPolicyAGameRound($gameRound, $futureFirst, $afterRG1Callback);
            } else {
                $this->runPolicyBGameRound($gameRound, $futureFirst, $afterRG1Callback);
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

    private function runPolicyAGameRound(int $gameRound, bool $futureFirst, ?callable $afterRG1Callback): void
    {
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

        $this->applyJointPostRoundBreak($gameRound);

        if ($gameRound === 1 && $afterRG1Callback !== null) {
            $afterRG1Callback($this->challenge->rTime());
            $this->future->rTime()->advanceToLater($this->challenge->rTime()->current());
        }
    }

    private function runPolicyBGameRound(int $gameRound, bool $futureFirst, ?callable $afterRG1Callback): void
    {
        $this->syncSharedGameClock();
        $anchor = $this->challenge->rTime()->current();

        $dryRun = $this->planPolicyBRound($gameRound, $anchor);

        $order = $futureFirst ? ['future', 'challenge'] : ['challenge', 'future'];
        foreach ($order as $key) {
            $this->runJudgingUntilGameRound($key, $gameRound, $dryRun['meta'][$key], $anchor);
            $this->syncSharedGameClock();
        }

        // Align may have delayed the shared clock — re-plan from the final anchor.
        $commitAnchor = $this->challenge->rTime()->current();
        $plan = $this->planPolicyBRound($gameRound, $commitAnchor);

        $chGroup = $this->challenge->robotGame()->ensureRoundGroup($gameRound);
        $f8Group = $this->future->robotGame()->ensureRoundGroup($gameRound);

        $roundEnd = $commitAnchor;
        foreach ($plan['events'] as $event) {
            $rg = $event['program'] === 'challenge'
                ? $this->challenge->robotGame()
                : $this->future->robotGame();
            $rg->activateGroup($event['program'] === 'challenge' ? $chGroup : $f8Group);
            $rg->writeMatchAt($event['match'], $gameRound, $event['start'], allowRobotCheck: false);
            if ($event['end'] > $roundEnd) {
                $roundEnd = clone $event['end'];
            }
        }

        if (! $roundEnd instanceof DateTime) {
            $roundEnd = DateTime::createFromInterface($roundEnd);
        }
        $this->challenge->rTime()->set($roundEnd);
        $this->future->rTime()->set($roundEnd);

        // After zip commit, refresh jEarliest from actual early-match ends (no robot check).
        $this->refreshPolicyBJudgingEarliest($gameRound, $plan);

        $this->applyJointPostRoundBreak($gameRound);

        if ($gameRound === 1 && $afterRG1Callback !== null) {
            $afterRG1Callback($this->challenge->rTime());
            $this->future->rTime()->advanceToLater($this->challenge->rTime()->current());
        }
    }

    /**
     * @return array{
     *     events: list<array{program: string, index: int, start: DateTime, end: DateTime, match: array}>,
     *     meta: array<string, array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}>
     * }
     */
    private function planPolicyBRound(int $gameRound, \DateTimeInterface $anchor): array
    {
        $chMatches = $this->challenge->robotGame()->matchPlan()->entriesForRound($gameRound);
        $f8Matches = $this->future->robotGame()->matchPlan()->entriesForRound($gameRound);

        $scheduler = new PolicyBRoundScheduler;
        $plan = $scheduler->plan(
            $chMatches,
            $f8Matches,
            (int) $this->pp('r_tables'),
            (int) $this->pp('f8_fields'),
            (int) $this->pp('r_duration_match'),
            (int) $this->pp('f8_r_duration_match'),
            (int) $this->pp('r_duration_next_start'),
            (int) $this->pp('f8_r_duration_next_start'),
            (int) $this->pp('f8_r_duration_next_start'),
            (bool) $this->pp('f8_future_first', false),
            $anchor,
        );

        return $plan;
    }

    /**
     * @param  array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}|null  $roundMeta
     */
    private function runJudgingUntilGameRound(
        string $key,
        int $gameRound,
        ?array $roundMeta = null,
        ?\DateTimeInterface $sharedAnchor = null,
    ): void {
        $prog = $this->program($key);
        $max = $this->judgingRoundCount($key);

        while ($this->nextJudgingBlock[$key] <= $max) {
            $block = $this->nextJudgingBlock[$key];
            $mapped = $prog->gameRoundForJudgingBlock($block);

            $timing = null;
            if ($roundMeta !== null && $sharedAnchor !== null && $mapped === $gameRound) {
                $timing = $this->policyBTimingForBlock($key, $block, $roundMeta, $sharedAnchor);
            }

            $prog->runJudgingBlock(
                $block,
                $this->jEarliest[$key],
                $this->teamOffset[$key],
                $timing
            );
            $this->nextJudgingBlock[$key]++;

            if ($mapped === $gameRound) {
                return;
            }
        }
    }

    /**
     * @param  array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}  $roundMeta
     * @return array{rT2MMinutes: int, rA4JMinutes: int}
     */
    private function policyBTimingForBlock(
        string $key,
        int $block,
        array $roundMeta,
        \DateTimeInterface $sharedAnchor,
    ): array {
        $prog = $this->program($key);
        $protectedIndex = $prog->protectedMatchIndexForBlock($block);
        $meta = PolicyBRoundScheduler::withProtectedMatch($roundMeta, $protectedIndex);

        $protected = $meta['protectedMatchStart'] ?? $meta['roundStart'] ?? $sharedAnchor;
        $rT2M = PolicyBRoundScheduler::minutesBetween($sharedAnchor, $protected);

        $jRounds = $key === 'challenge' ? (int) $this->pp('j_rounds') : (int) $this->pp('f8_j_rounds');
        if ($jRounds > 4 && $block === 2) {
            return ['rT2MMinutes' => $rT2M, 'rA4JMinutes' => 0];
        }

        $lanes = $key === 'challenge' ? (int) $this->pp('j_lanes') : (int) $this->pp('f8_lanes');
        $earlyIdx = (int) ceil($lanes / 2) - 1;
        $duration = $key === 'challenge'
            ? (int) $this->pp('r_duration_match')
            : (int) $this->pp('f8_r_duration_match');
        $transfer = $key === 'challenge'
            ? (int) $this->pp('c_duration_transfer')
            : (int) $this->pp('f8_duration_transfer');

        $earlyStart = $meta['starts'][$earlyIdx] ?? $meta['roundStart'] ?? $sharedAnchor;
        $earlyEnd = DateTime::createFromInterface($earlyStart);
        $earlyEnd->modify("+{$duration} minutes");
        $rA4J = PolicyBRoundScheduler::minutesBetween($sharedAnchor, $earlyEnd) + $transfer;

        return ['rT2MMinutes' => $rT2M, 'rA4JMinutes' => $rA4J];
    }

    /**
     * @param  array{events: list<array{program: string, index: int, start: DateTime, end: DateTime, match: array}>, meta: array<string, mixed>}  $plan
     */
    private function refreshPolicyBJudgingEarliest(int $gameRound, array $plan): void
    {
        foreach (['challenge', 'future'] as $key) {
            $prog = $this->program($key);
            $block = $this->nextJudgingBlock[$key] - 1;
            if ($block < 1) {
                continue;
            }
            if ($prog->gameRoundForJudgingBlock($block) !== $gameRound) {
                continue;
            }

            $jRounds = $key === 'challenge' ? (int) $this->pp('j_rounds') : (int) $this->pp('f8_j_rounds');
            if ($jRounds > 4 && $block === 2) {
                continue;
            }

            $lanes = $key === 'challenge' ? (int) $this->pp('j_lanes') : (int) $this->pp('f8_lanes');
            $earlyIdx = (int) ceil($lanes / 2) - 1;
            $duration = $key === 'challenge'
                ? (int) $this->pp('r_duration_match')
                : (int) $this->pp('f8_r_duration_match');
            $transfer = $key === 'challenge'
                ? (int) $this->pp('c_duration_transfer')
                : (int) $this->pp('f8_duration_transfer');

            $starts = $plan['meta'][$key]['starts'] ?? [];
            $earlyStart = $starts[$earlyIdx] ?? $plan['meta'][$key]['roundStart'] ?? null;
            if ($earlyStart === null) {
                continue;
            }

            $earliest = DateTime::createFromInterface($earlyStart);
            $earliest->modify('+'.($duration + $transfer).' minutes');
            $this->jEarliest[$key]->set($earliest);
        }
    }

    private function program(string $key): ChallengeGenerator|Future8Generator
    {
        return $key === 'challenge' ? $this->challenge : $this->future;
    }

    private function judgingRoundCount(string $key): int
    {
        return $this->program($key)->judgingRoundCount();
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
