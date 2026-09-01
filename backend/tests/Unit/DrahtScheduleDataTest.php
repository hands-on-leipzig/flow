<?php

namespace Tests\Unit;

use App\Support\DrahtScheduleData;
use Tests\TestCase;

class DrahtScheduleDataTest extends TestCase
{
    public function test_normalize_valid_payload(): void
    {
        $result = DrahtScheduleData::normalize([
            'id' => 665,
            'capacity_teams' => 12,
            'teams' => [
                '1046' => ['ref' => '1046', 'name' => 'GGI', 'organization' => 'School'],
            ],
        ]);

        $this->assertFalse($result['program_gone']);
        $this->assertSame(12, $result['capacity']);
        $this->assertCount(1, $result['teams']);
    }

    public function test_normalize_marks_removed_program_gone(): void
    {
        $result = DrahtScheduleData::normalize([
            'id' => null,
            'capacity_teams' => null,
            'teams' => 'SQL error',
        ]);

        $this->assertTrue($result['program_gone']);
        $this->assertSame([], $result['teams']);
        $this->assertSame(0, $result['capacity']);
    }

    public function test_normalize_zero_capacity_is_gone(): void
    {
        $result = DrahtScheduleData::normalize([
            'id' => 733,
            'capacity_teams' => 0,
            'teams' => [],
        ]);

        $this->assertTrue($result['program_gone']);
    }

    public function test_team_detail_rows_extracts_organization(): void
    {
        $rows = DrahtScheduleData::teamDetailRows([
            ['ref' => '1046', 'name' => 'GGI', 'organization' => ' Gym ', 'location' => 'City'],
        ]);

        $this->assertSame([
            [
                'team_number_hot' => 1046,
                'organization' => 'Gym',
                'location' => 'City',
            ],
        ], $rows);
    }
}
