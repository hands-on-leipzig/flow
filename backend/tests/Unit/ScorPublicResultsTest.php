<?php

namespace Tests\Unit;

use App\Scor\ScorPublicResults;
use PHPUnit\Framework\TestCase;

class ScorPublicResultsTest extends TestCase
{
    public function test_public_results_hide_jury_scores_and_overall_ranking(): void
    {
        $public = (new ScorPublicResults())->fromResults([
            'event' => ['id' => 1, 'name' => 'Leipzig'],
            'rounds' => [['round' => 1, 'label' => 'Runde 1']],
            'teams' => [
                $this->team(1, 101, 'Alpha', robotRank: 2, robot: 40, championRank: 1),
                $this->team(2, 102, 'Beta', robotRank: 1, robot: 55, championRank: 2),
                $this->team(3, 103, 'Gamma', robotRank: 3, robot: 10, championRank: 3),
            ],
            'awards' => [
                'trophies' => [
                    ['team' => 1, 'category' => 'champion'],
                    ['team' => 3, 'category' => 'research'],
                ],
                'nominations' => [
                    ['team' => 3, 'category' => 'research'],
                    ['team' => 2, 'category' => 'research'],
                ],
                'coaching' => 2,
            ],
            'can_edit_awards' => true,
            'lifecycle' => ['closed' => true, 'released' => true, 'mail_body' => 'intern'],
        ]);

        $this->assertSame(['event', 'rounds', 'game', 'podium', 'categories', 'coaching'], array_keys($public));
        $this->assertSame([102, 101, 103], array_column($public['game'], 'number'));
        $this->assertSame([1, 2, 3], array_column($public['game'], 'rank'));
        $this->assertSame([['round' => 1, 'points' => 55]], $public['game'][0]['rounds']);
        $this->assertSame([['place' => 'champion', 'team' => ['number' => 101, 'name' => 'Alpha']]], $public['podium']);

        $research = $public['categories'][0];
        $this->assertSame('research', $research['category']);
        $this->assertSame([102, 103], array_column($research['nominees'], 'number'));
        $this->assertSame([false, true], array_column($research['nominees'], 'trophy'));
        $this->assertSame([], $public['categories'][1]['nominees']);
        $this->assertSame(['number' => 102, 'name' => 'Beta'], $public['coaching']);

        $json = json_encode($public);
        foreach (['"champion":', '_rank":', '"research":', '"robotdesign":', '"core_values":', 'mail_body', 'can_edit', '"id":1,"number"'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function team(int $id, int $number, string $name, int $robotRank, int $robot, int $championRank): array
    {
        return [
            'id' => $id,
            'number' => $number,
            'name' => $name,
            'rounds' => [['round' => 1, 'points' => $robot, 'confirmed' => true]],
            'robot' => $robot,
            'research' => 8,
            'robotdesign' => 7,
            'core_values' => 6,
            'robot_rank' => $robotRank,
            'research_rank' => 1,
            'robotdesign_rank' => 1,
            'core_values_rank' => 1,
            'champion' => 4,
            'champion_rank' => $championRank,
        ];
    }
}
