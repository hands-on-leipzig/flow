<?php

namespace Tests\Unit;

use App\Support\PreviewGridOverlapResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class PreviewGridOverlapResolverTest extends TestCase
{
    private function event(
        string $column,
        string $start,
        string $end,
        string $text = 'X',
        int $rowspan = 2,
    ): array {
        return [
            'column_key' => $column,
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($end),
            'text' => $text,
            'rowspan' => $rowspan,
            'style_column' => 'Challenge',
        ];
    }

    public function test_no_overlap_leaves_events_unchanged(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'A'),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:20:00', 'B'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertFalse($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('A', $result['events'][0]['text']);
        $this->assertSame('10:00', $result['events'][0]['start']->format('H:i'));
        $this->assertArrayNotHasKey('overlap_adjusted', $result['events'][0]);
        $this->assertArrayNotHasKey('overlap_container', $result['events'][0]);
    }

    public function test_same_start_pair_shifts_second_and_flags_adjusted(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'J2'),
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:15:00', 'F1'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('J2', $result['events'][0]['text']);
        $this->assertSame('F1', $result['events'][1]['text']);
        $this->assertSame('10:20', $result['events'][1]['start']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
    }

    public function test_partial_overlap_shifts_second_to_first_end(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A'),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:25:00', 'B'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertSame('10:20', $result['events'][1]['start']->format('H:i'));
        $this->assertSame('10:25', $result['events'][1]['end']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
        $this->assertSame(1, $result['events'][1]['rowspan']);
    }

    public function test_contained_overlap_flags_container_not_adjusted(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A'),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:15:00', 'B'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertTrue($result['events'][0]['overlap_container']);
        $this->assertSame('10:10', $result['events'][1]['start']->format('H:i'));
        $this->assertArrayNotHasKey('overlap_adjusted', $result['events'][1]);
    }

    public function test_same_start_equal_end_keeps_second_as_one_black_slot(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'J2', 2),
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'F1', 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('F1', $result['events'][1]['text']);
        $this->assertSame('10:10', $result['events'][1]['start']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
        $this->assertSame(1, $result['events'][1]['rowspan']);
    }

    public function test_partial_overlap_with_short_tail_keeps_shifted_event(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A'),
            $this->event('c1', '2026-03-01 10:19:00', '2026-03-01 10:21:00', 'B'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('10:20', $result['events'][1]['start']->format('H:i'));
        $this->assertSame('10:21', $result['events'][1]['end']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
        $this->assertSame(1, $result['events'][1]['rowspan']);
    }

    public function test_overlaps_in_different_columns_are_independent(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A'),
            $this->event('c2', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'B'),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertFalse($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
    }
}
