<?php

namespace Tests\Unit;

use App\Services\MatchPlanPairingQuality;
use PHPUnit\Framework\TestCase;

class MatchPlanPairingQualityTest extends TestCase
{
    public function test_meeting_matrix_and_q_metrics_for_simple_plan(): void
    {
        $matches = [
            // TR: 1vs2 on tables 1-2, 3vs0 on 1-2
            ['round' => 0, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 0, 'match_no' => 2, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 3, 'table_2_team' => 0],
            // R1 same tables as TR for Q4
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 1, 'match_no' => 2, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 3, 'table_2_team' => 0],
            // R2 different opponents
            ['round' => 2, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 2, 'match_no' => 2, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 2, 'table_2_team' => 0],
        ];

        $result = (new MatchPlanPairingQuality())->evaluate($matches, 3, 2);
        $matrix = $result['meeting_matrix'];

        $this->assertSame([1, 2], $result['scoring_rounds']);
        $this->assertSame(3, $result['q4_ok_count']);
        $this->assertSame([0, 1, 2, 3], $matrix['labels']);
        // indices: 0=team0, 1=team1, 2=team2, 3=team3
        $this->assertSame('1', $matrix['cells'][1][2]); // team1 vs team2 in R1
        $this->assertSame('2', $matrix['cells'][1][3]); // team1 vs team3 in R2
        $this->assertSame('1', $matrix['cells'][3][0]); // team3 vs 0 in R1
        $this->assertSame('2', $matrix['cells'][2][0]); // team2 vs 0 in R2
        $this->assertSame('', $matrix['cells'][1][1]);

        $team1 = $result['match_summary'][0];
        $this->assertSame(1, $team1['team']);
        $this->assertTrue($team1['q4_ok']);
        $this->assertSame(2, $team1['teams']); // opponents 2 and 3
        $this->assertTrue($team1['q3_ok']); // 2 scoring rounds → need 2 opponents
    }
}
