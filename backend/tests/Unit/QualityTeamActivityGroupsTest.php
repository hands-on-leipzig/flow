<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\QualityTeamActivityGroups;
use Tests\TestCase;

class QualityTeamActivityGroupsTest extends TestCase
{
    public function test_challenge_required_and_fetch_are_core_morning_groups(): void
    {
        $expected = [
            'j_package',
            'r_test_round',
            'r_round_1',
            'r_round_2',
            'r_round_3',
        ];

        $this->assertSame($expected, QualityTeamActivityGroups::required(FirstProgram::CHALLENGE->value));
        $this->assertSame($expected, QualityTeamActivityGroups::fetch(FirstProgram::CHALLENGE->value));
    }

    public function test_future_fetch_adds_catalog_rounds_four_and_five(): void
    {
        $required = [
            'f8_j_package',
            'f8_test_round',
            'f8_round_1',
            'f8_round_2',
            'f8_round_3',
        ];
        $fetch = [...$required, 'f8_round_4', 'f8_round_5'];

        $this->assertSame($required, QualityTeamActivityGroups::required(FirstProgram::FUTURE_8->value));
        $this->assertSame($fetch, QualityTeamActivityGroups::fetch(FirstProgram::FUTURE_8->value));
        $this->assertNotContains('f8_round_4', QualityTeamActivityGroups::required(FirstProgram::FUTURE_8->value));
        $this->assertNotContains('f8_round_5', QualityTeamActivityGroups::required(FirstProgram::FUTURE_8->value));
    }
}
