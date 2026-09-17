<?php

namespace Tests\Unit;

use App\Print\RoleSheetCells;
use PHPUnit\Framework\TestCase;

class RoleSheetCellsTest extends TestCase
{
    public function test_volunteer_label_for_team_zero_and_missing_name(): void
    {
        $activity = $this->matchActivity(
            table1Team: 1,
            table1Name: 'Alpha',
            table2Team: 0,
            table2Name: null,
        );

        $action = RoleSheetCells::action($activity, 'Team', 'team', 1, null);

        $this->assertSame(RoleSheetCells::VOLUNTEER, $action['text']);
        $this->assertSame('Freiwilliges Team ohne Wertung', $action['text']);
    }

    public function test_hot_label_is_four_digits(): void
    {
        $this->assertSame('Alpha (0042)', RoleSheetCells::hotLabel('Alpha', 42));
        $this->assertSame('Alpha', RoleSheetCells::hotLabel('Alpha', null));
    }

    public function test_team_match_appends_table_to_room(): void
    {
        $activity = $this->matchActivity(
            table1Team: 1,
            table1Name: 'Alpha',
            table2Team: 2,
            table2Name: 'Beta',
            table1: 3,
            table1Stored: null,
        );
        $activity['room'] = ['room_name' => 'Halle'];
        $activity['meta'] = ['first_program_id' => 3];

        $room = RoleSheetCells::room($activity, 'team', 1, null, 3);

        $this->assertSame('Halle, Tisch 3', $room);
    }

    public function test_ref_pair_is_own_first_with_hot(): void
    {
        $activity = $this->matchActivity(
            table1Team: 1,
            table1Name: 'Alpha',
            table1Hot: 12,
            table2Team: 2,
            table2Name: 'Beta',
            table2Hot: 7,
            table1: 1,
            table2: 2,
        );

        $action = RoleSheetCells::action($activity, 'Schiedsrichter:in', 'table', null, 2);

        $this->assertSame('Beta (0007) – Alpha (0012)', $action['text']);
    }

    public function test_jury_without_with_team_code_is_empty(): void
    {
        $activity = [
            'activity_type_code' => 'j_deliberation',
            'team_name' => 'Alpha',
            'jury_team_number_hot' => 12,
        ];

        $action = RoleSheetCells::action($activity, 'Juror:in', 'lane', null, null);

        $this->assertSame('', $action['text']);
    }

    public function test_moderator_match_uses_names_without_hot(): void
    {
        $activity = $this->matchActivity(
            table1Team: 1,
            table1Name: 'Alpha',
            table1Hot: 12,
            table2Team: 2,
            table2Name: 'Beta',
            table2Hot: 7,
        );

        $action = RoleSheetCells::action($activity, 'Moderator:in', '', null, null);

        $this->assertSame('Alpha – Beta', $action['text']);
        $this->assertStringNotContainsString('0012', $action['text']);
        $this->assertStringNotContainsString('0007', $action['text']);
    }

    public function test_pair_uses_en_dash(): void
    {
        $this->assertSame("Alpha\u{2013}Beta", str_replace(' – ', "\u{2013}", RoleSheetCells::pair('Alpha', 'Beta')));
        $this->assertSame('Alpha – Beta', RoleSheetCells::pair('Alpha', 'Beta'));
        $this->assertSame(' – ', mb_substr(RoleSheetCells::pair('A', 'B'), 1, 3));
    }

    public function test_volunteer_is_never_wrapped_with_hot(): void
    {
        $activity = $this->matchActivity(
            table1Team: 1,
            table1Name: 'Alpha',
            table1Hot: 12,
            table2Team: 0,
            table2Name: null,
            table2Hot: 99,
            table1: 1,
            table2: 2,
        );

        $action = RoleSheetCells::action($activity, 'Schiedsrichter:in', 'table', null, 1);

        $this->assertSame('Alpha (0012) – Freiwilliges Team ohne Wertung', $action['text']);
        $this->assertStringNotContainsString('0099', $action['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchActivity(
        int $table1Team,
        ?string $table1Name,
        int $table2Team,
        ?string $table2Name,
        mixed $table1Hot = null,
        mixed $table2Hot = null,
        int $table1 = 1,
        int $table2 = 2,
        ?string $table1Stored = 'Tisch 1',
    ): array {
        return [
            'activity_type_code' => 'r_match',
            'table_1' => $table1,
            'table_2' => $table2,
            'table_1_name' => $table1Stored,
            'table_2_name' => 'Tisch 2',
            'table_1_team' => $table1Team,
            'table_2_team' => $table2Team,
            'table_1_team_name' => $table1Name,
            'table_2_team_name' => $table2Name,
            'table_1_team_number_hot' => $table1Hot,
            'table_2_team_number_hot' => $table2Hot,
        ];
    }
}
