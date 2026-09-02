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
        ?int $rowspan = null,
        int $activityId = 1,
    ): array {
        $startAt = Carbon::parse($start);
        $endAt = Carbon::parse($end);
        if ($rowspan === null) {
            $minutes = max(5, (int) $startAt->diffInMinutes($endAt));
            $rowspan = max(1, (int) ceil($minutes / 5));
        }

        return [
            'column_key' => $column,
            'start' => $startAt,
            'end' => $endAt,
            'text' => $text,
            'rowspan' => $rowspan,
            'style_column' => 'Challenge',
            'activity_id' => $activityId,
        ];
    }

    public function test_no_overlap_leaves_events_unchanged(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'A', activityId: 1),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:20:00', 'B', activityId: 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertFalse($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
    }

    public function test_dedupes_identical_placements_from_one_activity(): void
    {
        $duplicate = $this->event('c1', '2026-03-01 12:10:00', '2026-03-01 12:15:00', 'A', 1, 42);

        $result = PreviewGridOverlapResolver::resolve([$duplicate, $duplicate]);

        $this->assertFalse($result['has_overlaps']);
        $this->assertCount(1, $result['events']);
        $this->assertSame('A', $result['events'][0]['text']);
        $this->assertArrayNotHasKey('overlap_adjusted', $result['events'][0]);
    }

    public function test_same_start_different_text_keeps_overlap_resolution(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'J2', activityId: 1),
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'F1', activityId: 1),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
    }

    public function test_same_start_pair_shifts_second_and_flags_adjusted(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'J2', activityId: 1),
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:15:00', 'F1', activityId: 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertSame('10:20', $result['events'][1]['start']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
    }

    public function test_partial_overlap_shifts_second_to_first_grid_end(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A', activityId: 1),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:25:00', 'B', activityId: 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertSame('10:20', $result['events'][1]['start']->format('H:i'));
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
    }

    public function test_contained_overlap_flags_container_not_adjusted(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:20:00', 'A', activityId: 1),
            $this->event('c1', '2026-03-01 10:10:00', '2026-03-01 10:15:00', 'B', activityId: 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertTrue($result['events'][0]['overlap_container']);
        $this->assertArrayNotHasKey('overlap_adjusted', $result['events'][1]);
    }

    public function test_same_start_equal_end_keeps_second_as_one_black_slot(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'J2', 2, 1),
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'F1', 2, 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertTrue($result['has_overlaps']);
        $this->assertSame('10:10', $result['events'][1]['start']->format('H:i'));
        $this->assertSame(1, $result['events'][1]['rowspan']);
        $this->assertTrue($result['events'][1]['overlap_adjusted']);
    }

    public function test_adjacent_five_minute_slots_with_inflated_end_are_not_overlaps(): void
    {
        $placed = [
            $this->event('c1', '2026-03-01 10:00:00', '2026-03-01 10:10:00', 'A', 1, 1),
            $this->event('c1', '2026-03-01 10:05:00', '2026-03-01 10:10:00', 'B', 1, 2),
        ];

        $result = PreviewGridOverlapResolver::resolve($placed);

        $this->assertFalse($result['has_overlaps']);
        $this->assertCount(2, $result['events']);
    }
}
