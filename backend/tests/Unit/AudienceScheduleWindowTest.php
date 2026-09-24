<?php

namespace Tests\Unit;

use App\Services\ActivityFetcherService;
use App\Services\AudienceSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class AudienceScheduleWindowTest extends TestCase
{
    public function test_window_keeps_full_rest_now_and_next(): void
    {
        $schedule = new AudienceSchedule(new ActivityFetcherService);
        $pivot = Carbon::parse('2026-06-01 10:00', 'Europe/Berlin');
        $rows = collect([
            (object) ['start_time' => '2026-06-01 09:00', 'end_time' => '2026-06-01 09:30'],
            (object) ['start_time' => '2026-06-01 09:30', 'end_time' => '2026-06-01 10:30'],
            (object) ['start_time' => '2026-06-01 10:15', 'end_time' => '2026-06-01 10:45'],
            (object) ['start_time' => '2026-06-01 11:00', 'end_time' => '2026-06-01 11:30'],
        ]);

        $this->assertCount(4, $schedule->window($rows, 'full', $pivot, 30));
        $this->assertCount(3, $schedule->window($rows, 'rest', $pivot, 30));
        $this->assertSame(
            ['2026-06-01 09:30'],
            $schedule->window($rows, 'now', $pivot, 30)->pluck('start_time')->all(),
        );
        $this->assertSame(
            ['2026-06-01 10:15'],
            $schedule->window($rows, 'next', $pivot, 30)->pluck('start_time')->all(),
        );
    }
}
