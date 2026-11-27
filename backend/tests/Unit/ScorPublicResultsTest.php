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

        $this->assertSame(['event', 'awards', 'game'], array_keys($public));
        $this->assertSame(['podium', 'categories', 'coaching', 'game'], array_keys($public['awards']));
        $this->assertSame(['rounds', 'teams'], array_keys($public['game']));

        $game = $public['game']['teams'];
        $this->assertSame([102, 101, 103], array_column($game, 'number'));
        $this->assertSame([1, 2, 3], array_column($game, 'rank'));
        $this->assertSame([55, 40, 10], array_column($game, 'best'));
        $this->assertSame([['round' => 1, 'points' => 55]], $game[0]['rounds']);

        $awards = $public['awards'];
        $this->assertSame([['place' => 'champion', 'team' => ['number' => 101, 'name' => 'Alpha']]], $awards['podium']);
        $research = $awards['categories'][0];
        $this->assertSame('research', $research['category']);
        $this->assertSame([102, 103], array_column($research['nominees'], 'number'));
        $this->assertSame(['number' => 103, 'name' => 'Gamma'], $research['winner']);
        $this->assertSame([], $awards['categories'][1]['nominees']);
        $this->assertNull($awards['categories'][1]['winner']);
        $this->assertSame(['number' => 102, 'name' => 'Beta'], $awards['coaching']);
        $this->assertSame([['number' => 102, 'name' => 'Beta']], $awards['game']);

        $awardsJson = json_encode($awards);
        foreach (['rank"', '"points"', '"average"', '"best"', '"robot"', '"rounds"'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $awardsJson);
        }

        $json = json_encode($public);
        foreach (['"champion":', '_rank":', '"research":', '"robotdesign":', '"core_values":', 'mail_body', 'can_edit', '"id":1,"number"'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    public function test_no_spiel_trophy_while_nobody_has_points(): void
    {
        $public = (new ScorPublicResults())->fromResults([
            'event' => ['id' => 1, 'name' => 'Leipzig'],
            'rounds' => [['round' => 1, 'label' => 'Runde 1']],
            'teams' => [
                ['id' => 1, 'number' => 101, 'name' => 'Alpha', 'rounds' => [null], 'robot' => 0, 'robot_rank' => 1],
            ],
            'awards' => ['trophies' => [], 'nominations' => [], 'coaching' => null],
        ]);

        $this->assertSame([], $public['awards']['game']);
        $this->assertNull($public['game']['teams'][0]['best']);
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
