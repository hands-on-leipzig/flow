<?php

namespace Tests\Unit;

use App\Services\DrahtPlanFollowUp;
use PHPUnit\Framework\TestCase;

class DrahtPlanFollowUpTest extends TestCase
{
    private DrahtPlanFollowUp $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DrahtPlanFollowUp;
    }

    public function test_later_date_shifts_forward_and_generates(): void
    {
        $this->assertSame(
            ['shiftDays' => 7, 'generate' => true],
            $this->service->decide('2026-01-01', '2026-01-08', false)
        );
    }

    public function test_earlier_date_shifts_backward_and_generates(): void
    {
        $this->assertSame(
            ['shiftDays' => -7, 'generate' => true],
            $this->service->decide('2026-01-08', '2026-01-01', false)
        );
    }

    public function test_missing_or_equal_dates_do_not_shift(): void
    {
        $this->assertSame(
            ['shiftDays' => 0, 'generate' => false],
            $this->service->decide(null, '2026-01-08', false)
        );
        $this->assertSame(
            ['shiftDays' => 0, 'generate' => false],
            $this->service->decide('2026-01-01', null, false)
        );
        $this->assertSame(
            ['shiftDays' => 0, 'generate' => false],
            $this->service->decide('2026-01-01', '2026-01-01', false)
        );
        $this->assertSame(
            ['shiftDays' => 0, 'generate' => true],
            $this->service->decide('2026-01-01', '2026-01-01', true)
        );
    }
}
