<?php

namespace Tests\Unit;

use App\Core\PolicyBRoundScheduler;
use DateTime;
use PHPUnit\Framework\TestCase;

class PolicyBRoundSchedulerTest extends TestCase
{
    private PolicyBRoundScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new PolicyBRoundScheduler;
    }

    public function test_both_two_fields_challenge_first_uses_d_minus_ns(): void
    {
        $ch = $this->matchList(3);
        $f8 = $this->matchList(3);
        $anchor = new DateTime('2026-01-01 10:00:00');

        $plan = $this->scheduler->plan(
            $ch,
            $f8,
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            $anchor,
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['start']->format('H:i')],
            $plan['events']
        );

        $this->assertSame([
            ['challenge', '10:00'],
            ['future', '10:05'],
            ['challenge', '10:15'],
            ['future', '10:20'],
            ['challenge', '10:30'],
            ['future', '10:35'],
        ], $starts);

        $this->assertSame('10:00', $plan['meta']['challenge']['roundStart']->format('H:i'));
        $this->assertSame('10:40', $plan['meta']['challenge']['roundEnd']->format('H:i'));
        $this->assertSame('10:05', $plan['meta']['future']['roundStart']->format('H:i'));
        $this->assertSame('10:50', $plan['meta']['future']['roundEnd']->format('H:i'));
    }

    public function test_both_two_fields_future_first(): void
    {
        $plan = $this->scheduler->plan(
            $this->matchList(2),
            $this->matchList(2),
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            true,
            new DateTime('2026-01-01 10:00:00'),
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['start']->format('H:i')],
            $plan['events']
        );

        $this->assertSame([
            ['future', '10:00'],
            ['challenge', '10:10'],
            ['future', '10:15'],
            ['challenge', '10:25'],
        ], $starts);
    }

    public function test_four_and_four_waves_shared_ns(): void
    {
        $plan = $this->scheduler->plan(
            $this->matchList(4),
            $this->matchList(4),
            4,
            4,
            15,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['index'], $e['start']->format('H:i')],
            $plan['events']
        );

        // C wave 0,1 then F8 0,1 then C 2,3 then F8 2,3 — shared ns=5 between every start.
        $this->assertSame([
            ['challenge', 0, '10:00'],
            ['challenge', 1, '10:05'],
            ['future', 0, '10:10'],
            ['future', 1, '10:15'],
            ['challenge', 2, '10:20'],
            ['challenge', 3, '10:25'],
            ['future', 2, '10:30'],
            ['future', 3, '10:35'],
        ], $starts);
    }

    public function test_half_wave_then_flip(): void
    {
        $plan = $this->scheduler->plan(
            $this->matchList(3),
            $this->matchList(2),
            4,
            2,
            15,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['index'], $e['start']->format('H:i')],
            $plan['events']
        );

        $this->assertSame([
            ['challenge', 0, '10:00'],
            ['challenge', 1, '10:05'],
            ['future', 0, '10:10'],
            ['challenge', 2, '10:15'], // half-wave
            ['future', 1, '10:20'],
        ], $starts);
    }

    public function test_drain_after_shorter_program_exhausted(): void
    {
        // C 2-field: 2 matches; F8 2-field: 4 matches — after zip, drain F8 with solo D advances.
        $plan = $this->scheduler->plan(
            $this->matchList(2),
            $this->matchList(4),
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['index'], $e['start']->format('H:i')],
            $plan['events']
        );

        // Zip: C0@10:00, F0@10:05, C1@10:15 (C empty), F1@10:20 still in zip pair.
        // Drain: zip advance after F1 (Df8−ns=10) → F2@10:30; solo D=15 → F3@10:45.
        $this->assertSame([
            ['challenge', 0, '10:00'],
            ['future', 0, '10:05'],
            ['challenge', 1, '10:15'],
            ['future', 1, '10:20'],
            ['future', 2, '10:30'],
            ['future', 3, '10:45'],
        ], $starts);
    }

    public function test_empty_match_still_consumes_start_slot(): void
    {
        $ch = [
            ['round' => 1, 'match' => 1, 'table_1' => 1, 'table_2' => 2, 'team_1' => 0, 'team_2' => 0],
            ['round' => 1, 'match' => 2, 'table_1' => 1, 'table_2' => 2, 'team_1' => 1, 'team_2' => 2],
        ];
        $f8 = $this->matchList(1);

        $plan = $this->scheduler->plan(
            $ch,
            $f8,
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $this->assertCount(3, $plan['events']);
        $this->assertSame(0, $plan['events'][0]['match']['team_1']);
        $this->assertSame('10:00', $plan['events'][0]['start']->format('H:i'));
        $this->assertSame('10:05', $plan['events'][1]['start']->format('H:i'));
        $this->assertSame('10:15', $plan['events'][2]['start']->format('H:i'));
    }

    public function test_c2_f8_4_wave_and_single(): void
    {
        $plan = $this->scheduler->plan(
            $this->matchList(2),
            $this->matchList(4),
            2,
            4,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $starts = array_map(
            fn ($e) => [$e['program'], $e['index'], $e['start']->format('H:i')],
            $plan['events']
        );

        // C single, F8 wave of 2, C single, F8 wave of 2 — shared ns
        $this->assertSame([
            ['challenge', 0, '10:00'],
            ['future', 0, '10:05'],
            ['future', 1, '10:10'],
            ['challenge', 1, '10:15'],
            ['future', 2, '10:20'],
            ['future', 3, '10:25'],
        ], $starts);
    }

    /** @return list<array<string, mixed>> */
    private function matchList(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'round' => 1,
                'match' => $i,
                'table_1' => 1,
                'table_2' => 2,
                'team_1' => $i,
                'team_2' => $i + 10,
            ];
        }

        return $out;
    }
}
