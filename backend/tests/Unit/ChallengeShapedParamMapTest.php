<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\ChallengeShapedParamMap;
use PHPUnit\Framework\TestCase;

class ChallengeShapedParamMapTest extends TestCase
{
    public function test_challenge_robot_check_and_future_alliance_meeting(): void
    {
        $challenge = ChallengeShapedParamMap::from(FirstProgram::CHALLENGE);
        $this->assertTrue($challenge->supportsRobotCheck());
        $this->assertSame('r_robot_check', $challenge->robotCheck());

        $future = ChallengeShapedParamMap::from(FirstProgram::FUTURE_8);
        $this->assertTrue($future->supportsRobotCheck());
        $this->assertSame('f8_r_alliance_meeting', $future->robotCheck());
    }
}
