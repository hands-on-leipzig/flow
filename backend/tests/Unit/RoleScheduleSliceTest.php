<?php

namespace Tests\Unit;

use App\Support\RoleScheduleSlice;
use Tests\TestCase;

class RoleScheduleSliceTest extends TestCase
{
    public function test_lane_two_keeps_null_lane_and_lane_two_drops_lane_one(): void
    {
        $keep = fn (): bool => true;

        $this->assertTrue(RoleScheduleSlice::matches(
            (object) ['lane' => 2, 'table_1' => null, 'table_2' => null],
            2,
            null,
            null,
            $keep,
        ));
        $this->assertTrue(RoleScheduleSlice::matches(
            (object) ['lane' => null, 'table_1' => null, 'table_2' => null],
            2,
            null,
            null,
            $keep,
        ));
        $this->assertFalse(RoleScheduleSlice::matches(
            (object) ['lane' => 1, 'table_1' => null, 'table_2' => null],
            2,
            null,
            null,
            $keep,
        ));
    }
}
