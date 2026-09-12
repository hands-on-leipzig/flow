<?php

namespace Tests\Unit;

use App\Core\RobotGameWriteConfig;
use PHPUnit\Framework\TestCase;

class RobotGameWriteConfigTest extends TestCase
{
    public function test_future_game_round_break_is_at_least_transfer(): void
    {
        $this->assertSame(15, RobotGameWriteConfig::gameRoundBreakMinutes(10, 15));
        $this->assertSame(20, RobotGameWriteConfig::gameRoundBreakMinutes(20, 15));
        $this->assertSame(15, RobotGameWriteConfig::gameRoundBreakMinutes(15, 15));
    }

    public function test_challenge_keeps_catalog_break_when_transfer_is_null(): void
    {
        $this->assertSame(10, RobotGameWriteConfig::gameRoundBreakMinutes(10, null));
        $this->assertNull(RobotGameWriteConfig::challenge()->durationTransfer);
        $this->assertSame('f8_duration_transfer', RobotGameWriteConfig::future8()->durationTransfer);
    }
}
