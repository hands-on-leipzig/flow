<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\ContaoResultMatch;
use PHPUnit\Framework\TestCase;

class ContaoResultMatchTest extends TestCase
{
    public function test_season_label_uses_the_flow_year(): void
    {
        $this->assertSame('2026/27', ContaoResultMatch::seasonLabel(2026));
        $this->assertNull(ContaoResultMatch::seasonLabel(null));
    }

    public function test_challenge_and_explore_use_different_contao_season_types(): void
    {
        $this->assertSame('FLL', ContaoResultMatch::seasonType(FirstProgram::CHALLENGE));
        $this->assertSame('FLL Junior', ContaoResultMatch::seasonType(FirstProgram::EXPLORE));
    }

    public function test_event_date_picks_its_own_season_among_several_flagged_ones(): void
    {
        $seasons = [
            $this->season(68, '2024-04-01', '2025-04-30', true),
            $this->season(70, '2025-04-22', '2026-04-19', true),
            $this->season(72, '2026-04-30', '2027-05-31', true),
        ];

        $this->assertSame(72, ContaoResultMatch::pickSeasonId($seasons, '2026-11-28'));
        $this->assertSame(70, ContaoResultMatch::pickSeasonId($seasons, '2025-11-29'));
    }

    public function test_stored_competition_is_kept_when_it_already_belongs_to_the_season(): void
    {
        $stored = ['id' => 1637, 'name' => 'Chur', 'season' => 70];

        $this->assertSame(1637, ContaoResultMatch::pickRegionId($stored, 70, []));
    }

    public function test_an_old_competition_id_resolves_to_the_same_name_in_the_event_season(): void
    {
        $stored = ['id' => 1297, 'name' => 'Chur', 'season' => 43];
        $inSeason = [
            ['id' => 1800, 'name' => 'Aachen'],
            ['id' => 1855, 'name' => 'Chur'],
        ];

        $this->assertSame(1855, ContaoResultMatch::pickRegionId($stored, 72, $inSeason));
    }

    public function test_old_competition_without_a_namesake_yields_no_region(): void
    {
        $stored = ['id' => 1297, 'name' => 'Chur', 'season' => 43];

        $this->assertNull(ContaoResultMatch::pickRegionId($stored, 72, [
            ['id' => 1821, 'name' => 'Finale 2026/27'],
        ]));
    }

    public function test_a_virtual_namesake_does_not_hide_the_exact_competition(): void
    {
        $stored = ['id' => 1297, 'name' => 'Chur', 'season' => 43];

        $this->assertSame(1, ContaoResultMatch::pickRegionId($stored, 72, [
            ['id' => 1, 'name' => 'Chur'],
            ['id' => 2, 'name' => 'Chur &#40;virtuell&#41;'],
        ]));
    }

    public function test_several_competitions_with_the_same_name_are_not_guessed(): void
    {
        $stored = ['id' => 1297, 'name' => 'Chur', 'season' => 43];

        $this->assertNull(ContaoResultMatch::pickRegionId($stored, 72, [
            ['id' => 1, 'name' => 'Chur'],
            ['id' => 2, 'name' => 'Chur'],
        ]));
    }

    /**
     * @return array{id: int, season_start: int, season_end: int, use_for_evaluation_tool: string}
     */
    private function season(int $id, string $start, string $end, bool $evaluation): array
    {
        return [
            'id' => $id,
            'season_start' => strtotime($start.' 00:00:00'),
            'season_end' => strtotime($end.' 23:59:00'),
            'use_for_evaluation_tool' => $evaluation ? '1' : '',
        ];
    }
}
