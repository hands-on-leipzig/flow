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

    public function test_future_alliance_check_keys_challenge_keeps_robot_check(): void
    {
        $challenge = RobotGameWriteConfig::challenge();
        $this->assertSame('r_check', $challenge->checkCode);
        $this->assertSame('r_robot_check', $challenge->robotCheckParam);
        $this->assertSame('r_duration_robot_check', $challenge->durationCheck);

        $future = RobotGameWriteConfig::future8();
        $this->assertSame('f8_r_alliance', $future->checkCode);
        $this->assertSame('f8_r_alliance_meeting', $future->robotCheckParam);
        $this->assertSame('f8_r_duration_alliance_meeting', $future->durationCheck);
    }
}
