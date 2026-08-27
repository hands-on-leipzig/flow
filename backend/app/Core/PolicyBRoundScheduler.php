<?php

namespace App\Core;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Pure Policy B zip/drain timing for one game round (no DB writes).
 *
 * Match lists are untouched MatchPlan entries. This class only decides when each
 * entry starts on the shared clock.
 */
final class PolicyBRoundScheduler
{
    /**
     * @param  list<array<string, mixed>>  $challengeMatches
     * @param  list<array<string, mixed>>  $futureMatches
     * @return array{
     *     events: list<array{program: string, index: int, start: DateTime, end: DateTime, match: array}>,
     *     meta: array{
     *         challenge: array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime},
     *         future: array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}
     *     }
     * }
     */
    public function plan(
        array $challengeMatches,
        array $futureMatches,
        int $challengeFields,
        int $futureFields,
        int $challengeMatchDuration,
        int $futureMatchDuration,
        int $challengeNextStart,
        int $futureNextStart,
        int $sharedNextStart,
        bool $futureFirst,
        DateTimeInterface $anchor,
    ): array {
        if (! in_array($challengeFields, [2, 4], true) || ! in_array($futureFields, [2, 4], true)) {
            throw new InvalidArgumentException('Policy B supports 2 or 4 fields/tables only.');
        }

        $cursor = DateTime::createFromInterface($anchor);
        $events = [];

        $chIdx = 0;
        $f8Idx = 0;
        $chCount = count($challengeMatches);
        $f8Count = count($futureMatches);

        $chWave = $challengeFields === 4 ? 2 : 1;
        $f8Wave = $futureFields === 4 ? 2 : 1;
        $bothTwo = $challengeFields === 2 && $futureFields === 2;

        $first = $futureFirst ? 'future' : 'challenge';
        $second = $futureFirst ? 'challenge' : 'future';

        $lastProgram = null;

        while ($chIdx < $chCount || $f8Idx < $f8Count) {
            $chHas = $chIdx < $chCount;
            $f8Has = $f8Idx < $f8Count;

            if ($chHas && $f8Has) {
                // Zip: alternate units starting with $first.
                foreach ([$first, $second] as $program) {
                    $idx = $program === 'challenge' ? $chIdx : $f8Idx;
                    $count = $program === 'challenge' ? $chCount : $f8Count;
                    if ($idx >= $count) {
                        continue;
                    }

                    $wave = $program === 'challenge' ? $chWave : $f8Wave;
                    $take = min($wave, $count - $idx);

                    for ($w = 0; $w < $take; $w++) {
                        if ($lastProgram !== null) {
                            $advance = $this->zipAdvanceMinutes(
                                $lastProgram,
                                $bothTwo,
                                $challengeMatchDuration,
                                $futureMatchDuration,
                                $challengeNextStart,
                                $futureNextStart,
                                $sharedNextStart,
                            );
                            $cursor = $this->addMinutes($cursor, $advance);
                        }

                        $match = $program === 'challenge'
                            ? $challengeMatches[$chIdx]
                            : $futureMatches[$f8Idx];
                        $duration = $program === 'challenge'
                            ? $challengeMatchDuration
                            : $futureMatchDuration;
                        $index = $program === 'challenge' ? $chIdx : $f8Idx;

                        $start = clone $cursor;
                        $end = $this->addMinutes(clone $start, $duration);
                        $events[] = [
                            'program' => $program,
                            'index' => $index,
                            'start' => $start,
                            'end' => $end,
                            'match' => $match,
                        ];

                        if ($program === 'challenge') {
                            $chIdx++;
                        } else {
                            $f8Idx++;
                        }
                        $lastProgram = $program;
                    }
                }

                continue;
            }

            // Drain: one program left.
            $program = $chHas ? 'challenge' : 'future';
            $matches = $chHas ? $challengeMatches : $futureMatches;
            $fields = $chHas ? $challengeFields : $futureFields;
            $duration = $chHas ? $challengeMatchDuration : $futureMatchDuration;
            $ns = $chHas ? $challengeNextStart : $futureNextStart;
            $idxRef = $chHas ? $chIdx : $f8Idx;
            $count = $chHas ? $chCount : $f8Count;

            // Zip advance once into the surviving program, then solo advances.
            if ($lastProgram !== null) {
                $advance = $this->zipAdvanceMinutes(
                    $lastProgram,
                    $bothTwo,
                    $challengeMatchDuration,
                    $futureMatchDuration,
                    $challengeNextStart,
                    $futureNextStart,
                    $sharedNextStart,
                );
                $cursor = $this->addMinutes($cursor, $advance);
            }

            $draining = true;
            while ($idxRef < $count) {
                if (! $draining && $lastProgram === $program) {
                    $prev = $matches[$idxRef - 1];
                    $cursor = $this->addMinutes(
                        $cursor,
                        $this->soloAdvanceMinutes($prev, $fields, $duration, $ns)
                    );
                }
                $draining = false;

                $match = $matches[$idxRef];
                $start = clone $cursor;
                $end = $this->addMinutes(clone $start, $duration);
                $events[] = [
                    'program' => $program,
                    'index' => $idxRef,
                    'start' => $start,
                    'end' => $end,
                    'match' => $match,
                ];

                $idxRef++;
                $lastProgram = $program;
            }

            if ($chHas) {
                $chIdx = $idxRef;
            } else {
                $f8Idx = $idxRef;
            }
        }

        return [
            'events' => $events,
            'meta' => [
                'challenge' => $this->metaFor('challenge', $challengeMatches, $events),
                'future' => $this->metaFor('future', $futureMatches, $events),
            ],
        ];
    }

    /**
     * Minutes to advance after a match of $justPlayed before the next zip start.
     */
    private function zipAdvanceMinutes(
        string $justPlayed,
        bool $bothTwo,
        int $challengeMatchDuration,
        int $futureMatchDuration,
        int $challengeNextStart,
        int $futureNextStart,
        int $sharedNextStart,
    ): int {
        if (! $bothTwo) {
            return $sharedNextStart;
        }

        // C2+F8_2: after C → Dc−ns_c; after F8 → Df8−ns_f8.
        if ($justPlayed === 'challenge') {
            return $challengeMatchDuration - $challengeNextStart;
        }

        return $futureMatchDuration - $futureNextStart;
    }

    /**
     * Solo advance after a match (drain), same rule as RobotGameGenerator::advanceTimeForMatch.
     *
     * @param  array<string, mixed>  $match
     */
    private function soloAdvanceMinutes(array $match, int $fields, int $duration, int $nextStart): int
    {
        if ($fields === 2) {
            return $duration;
        }

        $matchNo = (int) ($match['match'] ?? 0);
        if ($matchNo % 2 === 1) {
            return $nextStart;
        }

        return $duration - $nextStart;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @param  list<array{program: string, index: int, start: DateTime, end: DateTime, match: array}>  $events
     * @return array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}
     */
    private function metaFor(string $program, array $matches, array $events): array
    {
        $starts = array_fill(0, count($matches), null);
        $roundStart = null;
        $roundEnd = null;

        foreach ($events as $event) {
            if ($event['program'] !== $program) {
                continue;
            }
            $starts[$event['index']] = clone $event['start'];
            if ($roundStart === null) {
                $roundStart = clone $event['start'];
            }
            $roundEnd = clone $event['end'];
        }

        return [
            'roundStart' => $roundStart,
            'roundEnd' => $roundEnd,
            'starts' => $starts,
            'protectedMatchStart' => null,
        ];
    }

    /**
     * Minutes from $from to $to (non-negative wall-clock).
     */
    public static function minutesBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) max(0, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 60));
    }

    /**
     * Fill protectedMatchStart from match index (0-based; same as align rMB).
     *
     * @param  array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}  $meta
     * @return array{roundStart: ?DateTime, roundEnd: ?DateTime, starts: list<?DateTime>, protectedMatchStart: ?DateTime}
     */
    public static function withProtectedMatch(array $meta, int $protectedIndex): array
    {
        if ($protectedIndex >= 0 && $protectedIndex < count($meta['starts']) && $meta['starts'][$protectedIndex] !== null) {
            $meta['protectedMatchStart'] = clone $meta['starts'][$protectedIndex];
        } elseif ($meta['roundStart'] !== null) {
            $meta['protectedMatchStart'] = clone $meta['roundStart'];
        }

        return $meta;
    }

    private function addMinutes(DateTime $time, int $minutes): DateTime
    {
        $copy = clone $time;
        if ($minutes !== 0) {
            $copy->modify(($minutes >= 0 ? '+' : '').$minutes.' minutes');
        }

        return $copy;
    }
}
