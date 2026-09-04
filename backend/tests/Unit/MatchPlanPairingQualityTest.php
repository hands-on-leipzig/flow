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

    public function test_q3_requires_n_distinct_opponents_for_four_scoring_rounds(): void
    {
        $matches = [
            ['round' => 0, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 2, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 3, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 4],
            ['round' => 4, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 5],
        ];

        $result = (new MatchPlanPairingQuality())->evaluate($matches, 5, 2);

        $this->assertSame([1, 2, 3, 4], $result['scoring_rounds']);
        $team1 = $result['match_summary'][0];
        $this->assertSame(4, $team1['teams']);
        $this->assertTrue($team1['q3_ok']);
        $this->assertSame(4, $team1['q3_target']);

        // Only three distinct opponents → fail for N=4
        $matchesFail = array_slice($matches, 0, 4); // rounds 0–3 only
        $fail = (new MatchPlanPairingQuality())->evaluate($matchesFail, 5, 2);
        $this->assertSame([1, 2, 3], $fail['scoring_rounds']);
        $this->assertTrue($fail['match_summary'][0]['q3_ok']); // 3 opponents for N=3

        $matchesFourRoundsThreeOpps = [
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 2, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 3, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 4],
            ['round' => 4, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2], // repeat opp 2
        ];
        $failN4 = (new MatchPlanPairingQuality())->evaluate($matchesFourRoundsThreeOpps, 5, 2);
        $this->assertSame([1, 2, 3, 4], $failN4['scoring_rounds']);
        $this->assertSame(3, $failN4['match_summary'][0]['teams']);
        $this->assertFalse($failN4['match_summary'][0]['q3_ok']);
    }

    public function test_q2_passes_with_at_least_three_of_four_tables(): void
    {
        $matches = [
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 2, 'match_no' => 1, 'table_1' => 2, 'table_2' => 3, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 3, 'match_no' => 1, 'table_1' => 3, 'table_2' => 4, 'table_1_team' => 1, 'table_2_team' => 4],
            ['round' => 4, 'match_no' => 1, 'table_1' => 4, 'table_2' => 1, 'table_1_team' => 1, 'table_2_team' => 5],
        ];

        $result = (new MatchPlanPairingQuality())->evaluate($matches, 5, 4);
        $team1 = $result['match_summary'][0];

        $this->assertSame(4, $team1['tables']);
        $this->assertTrue($team1['q2_ok']);
        $this->assertSame(3, $team1['q2_target']);

        $threeTables = [
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 2, 'match_no' => 1, 'table_1' => 2, 'table_2' => 3, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 3, 'match_no' => 1, 'table_1' => 3, 'table_2' => 1, 'table_1_team' => 1, 'table_2_team' => 4],
        ];
        $ok3 = (new MatchPlanPairingQuality())->evaluate($threeTables, 5, 4);
        $this->assertSame(3, $ok3['match_summary'][0]['tables']);
        $this->assertTrue($ok3['match_summary'][0]['q2_ok']);

        $twoTables = [
            ['round' => 1, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 2],
            ['round' => 2, 'match_no' => 1, 'table_1' => 2, 'table_2' => 1, 'table_1_team' => 1, 'table_2_team' => 3],
            ['round' => 3, 'match_no' => 1, 'table_1' => 1, 'table_2' => 2, 'table_1_team' => 1, 'table_2_team' => 4],
        ];
        $fail2 = (new MatchPlanPairingQuality())->evaluate($twoTables, 5, 4);
        $this->assertSame(2, $fail2['match_summary'][0]['tables']);
        $this->assertFalse($fail2['match_summary'][0]['q2_ok']);
    }
}
