<?php

namespace Tests\Unit;

use App\Print\RoleSheetCells;
use App\Print\RoomSheetCells;
use PHPUnit\Framework\TestCase;

class RoomSheetCellsTest extends TestCase
{
    public function test_match_includes_hot_pair_and_table_names(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Robot-Match',
            'activity_type_code' => 'r_match',
            'table_1_team' => 1,
            'table_1_team_name' => 'Alpha',
            'table_1_team_number_hot' => 1,
            'table_2_team' => 2,
            'table_2_team_name' => 'Beta',
            'table_2_team_number_hot' => 2,
            'table_1_name' => 'Tisch 1',
            'table_2_name' => 'Tisch 2',
        ]);

        $this->assertSame('Robot-Match, Alpha (0001) – Beta (0002), Tisch 1, Tisch 2', $action['text']);
        $this->assertSame([], $action['strike']);
    }

    public function test_judging_with_team_has_hot_and_no_table_extra(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Research',
            'activity_type_code' => 'j_with_team',
            'team' => 4,
            'jury_team_name' => 'Gamma',
            'jury_team_number_hot' => 12,
            'table_1_name' => 'Tisch 1',
        ]);

        $this->assertSame('Research, Gamma (0012)', $action['text']);
        $this->assertSame([], $action['strike']);
    }

    public function test_slot_block_uses_slot_team_label(): void
    {
        $action = RoomSheetCells::action([
            'activity_name' => 'Slot',
            'activity_type_code' => 'c_slot_block',
            'slot_team' => 3,
            'slot_team_name' => 'Delta',
            'slot_team_number_hot' => 9,
        ]);

        $this->assertSame('Slot, Delta (0009)', $action['text']);
    }

    public function test_robot_check_one_side_plus_table_name(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Robot-Check',
            'activity_type_code' => 'r_check',
            'table_1_team' => 1,
            'table_1_team_name' => 'Alpha',
            'table_1_team_number_hot' => 5,
            'table_1_name' => 'Tisch 1',
        ]);

        $this->assertSame('Robot-Check, Alpha (0005), Tisch 1', $action['text']);
    }

    public function test_both_null_match_emits_volunteer_pair(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Halbfinale',
            'activity_type_code' => 'r_match',
            'table_1_team' => null,
            'table_2_team' => null,
        ]);

        $pair = RoleSheetCells::pair(RoleSheetCells::VOLUNTEER, RoleSheetCells::VOLUNTEER);
        $this->assertSame('Halbfinale, '.$pair, $action['text']);
    }

    public function test_noshow_table_1_is_in_strike_list(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Robot-Match',
            'activity_type_code' => 'r_match',
            'table_1_team' => 1,
            'table_1_team_name' => 'Alpha',
            'table_1_team_number_hot' => 1,
            'table_1_team_noshow' => true,
            'table_2_team' => 2,
            'table_2_team_name' => 'Beta',
            'table_2_team_number_hot' => 2,
        ]);

        $this->assertContains('Alpha (0001)', $action['strike']);
        $this->assertSame('Robot-Match, Alpha (0001) – Beta (0002)', $action['text']);
    }

    public function test_opening_without_teams_is_atd_only(): void
    {
        $action = RoomSheetCells::action([
            'activity_atd_name' => 'Eröffnung',
            'activity_type_code' => 'g_opening',
        ]);

        $this->assertSame('Eröffnung', $action['text']);
        $this->assertSame([], $action['strike']);
    }
}
