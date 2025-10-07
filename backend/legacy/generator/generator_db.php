<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


// ***********************************************************************************
// Constants: Database IDs for ease of use
// ***********************************************************************************

// IDs from m_first_program
define('ID_FP_CHALLENGE', 3);
define('ID_FP_EXPLORE', 2);

// IDs from m_activity_type_detail

// Cross
define('ID_ATD_OPENING', 7);
define('ID_ATD_AWARDS', 34);

// FLL Challenge
define('ID_ATD_C_OPENING', 6);
define('ID_ATD_C_OPENING_DAY_1', 54);
define('ID_ATD_C_OPENING_DAY_3', 53);
define('ID_ATD_C_JUDGE_BRIEFING', 36);
define('ID_ATD_C_JUDGING_PACKAGE', 20);
define('ID_ATD_C_WITH_TEAM', 17);
define('ID_ATD_C_SCORING', 18);
define('ID_ATD_C_DELIBERATIONS', 19);
define('ID_ATD_C_LUNCH_BREAK', 21);
define('ID_ATD_C_COACH_BRIEFING', 35);
define('ID_ATD_C_JUDGE_BRIEFING_DAY_1', 55);

define('ID_ATD_C_AWARDS', 32);
define('ID_ATD_C_PRESENTATIONS', 33);

// FLL Challeng Robot Game
define('ID_ATD_R_REFEREE_BRIEFING', 37);
define('ID_ATD_R_ROUND_TEST', 8);
define('ID_ATD_R_ROUND_1', 9);
define('ID_ATD_R_ROUND_2', 10);
define('ID_ATD_R_ROUND_3', 11);
define('ID_ATD_R_FINAL_8', 12);
define('ID_ATD_R_FINAL_4', 13);
define('ID_ATD_R_FINAL_2', 14);
define('ID_ATD_R_MATCH', 15);
define('ID_ATD_R_CHECK', 16);
define('ID_ATD_R_FINAL_16', 45);
define('ID_ATD_R_REFEREE_DEBRIEFING', 46);

// FLL Explore
define('ID_ATD_E_OPENING', 5);
define('ID_ATD_E_JUDGING_PACKAGE', 4);
define('ID_ATD_E_WITH_TEAM', 1);
define('ID_ATD_E_SCORING', 2);
define('ID_ATD_E_DELIBERATIONS', 3);
define('ID_ATD_E_AWARDS', 31);
define('ID_ATD_E_COACH_BRIEFING', 38);
define('ID_ATD_E_JUDGE_BRIEFING', 39);

// Live Challenge
define('ID_ATD_LC_JUDGE_BRIEFING', 40);
define('ID_ATD_LC_JUDGING_PACKAGE', 41);
define('ID_ATD_LC_WITH_TEAM', 42);
define('ID_ATD_LC_SCORING', 43);
define('ID_ATD_LC_DELIBERATIONS', 44);

// Extra Blocks
define('ID_ATD_INSERTED', 47);
define('ID_ATD_E_INSERTED', 48);
define('ID_ATD_C_INSERTED', 49);
define('ID_ATD_FREE', 50);
define('ID_ATD_E_FREE', 51);
define('ID_ATD_C_FREE', 52);

// Insert Points
define('ID_IP_RG_TR', 6);
define('ID_IP_RG_1', 7);
define('ID_IP_RG_2', 8);
define('ID_IP_RG_3', 1);
define('ID_IP_PRESENTATIONS', 2);
define('ID_IP_RG_SEMI_FINAL', 4);
define('ID_IP_RG_FINAL', 3);

// IDs from m_room_type
define('ID_RT_R_MATCH', 1);
define('ID_RT_R_CHECK', 44);
define('ID_RT_C_LANE_1', 2);
define('ID_RT_C_LANE_2', 3);
define('ID_RT_C_LANE_3', 4);
define('ID_RT_C_LANE_4', 5);
define('ID_RT_C_LANE_5', 6);
define('ID_RT_C_LANE_6', 7);
define('ID_RT_E_LANE_1', 8);
define('ID_RT_E_LANE_2', 9);
define('ID_RT_E_LANE_3', 10);
define('ID_RT_E_LANE_4', 11);
define('ID_RT_E_LANE_5', 12);
define('ID_RT_E_LANE_6', 13);
define('ID_RT_OPENING', 14);
define('ID_RT_E_OPENING', 46);
define('ID_RT_C_OPENING', 45);
define('ID_RT_C_JUDGE_BRIEFING', 15);
define('ID_RT_E_JUDGE_BRIEFING', 16);
define('ID_RT_C_COACH', 17);
define('ID_RT_E_COACH', 18);
define('ID_RT_LUNCH_BREAK', 19);
define('ID_RT_E_EXIBITION', 22);
define('ID_RT_AWARDS', 23);
define('ID_RT_E_AWARDS', 47);
define('ID_RT_C_AWARDS', 48);
define('ID_RT_C_PRESENTATIONS', 24);
define('ID_RT_LC_JUDGE', 31);
define('ID_RT_LC_1', 25);
define('ID_RT_LC_2', 26);
define('ID_RT_LC_3', 27);
define('ID_RT_LC_4', 28);
define('ID_RT_LC_5', 29);
define('ID_RT_LC_6', 30);
define('ID_RT_OPENING_DAY_1', 32);
define('ID_RT_OPENING_DAY_3', 33);
define('ID_RT_C_JUDGE_BRIEFING_DAY_1', 34);
define('ID_RT_C_JUDGE_DELIBERATIONS', 35);
define('ID_RT_E_JUDGE_DELIBERATIONS', 36);

// FLL Explore modes
define('ID_E_MORNING', 1);
define('ID_E_AFTERNOON', 2);
define('ID_E_DECOUPLED_MORNING', 3);
define('ID_E_DECOUPLED_AFTERNOON', 4);
define('ID_E_DECOUPLED_BOTH', 5);


// ***********************************************************************************
// Reading from and adding to db tables (now via Laravel DB)
// ***********************************************************************************



function db_check_supported_plan($first_program, $teams, $lanes, $tables = null): bool
{
    $q = DB::table('m_supported_plan')
        ->where('first_program', $first_program)
        ->where('teams', $teams)
        ->where('lanes', $lanes);

    if (is_null($tables)) {
        $q->whereNull('tables');
    } else {
        $q->where('tables', $tables);
    }

    return $q->exists();
}

function db_insert_activity_group($activity_type_detail)
{
    global $g_activity_group;

    $id = DB::table('activity_group')->insertGetId([
        'plan' => pp('g_plan'),
        'activity_type_detail' => $activity_type_detail,
    ]);

    // keep global for legacy code
    $g_activity_group = $id;

    // also return for places that do $g_activity_group = db_insert_activity_group(...)
    return $id;
}

function db_insert_activity(
    $activity_type_detail,
    DateTime $time_start,
    $duration,
    $jury_lane = null, $jury_team = null,
    $table_1 = null, $table_1_team = null,
    $table_2 = null, $table_2_team = null
)
{
    global $g_activity_group;

    // Calculate end
    $time_end = clone $time_start;
    g_add_minutes($time_end, $duration);

    $start = $time_start->format('Y-m-d H:i:s');
    $end = $time_end->format('Y-m-d H:i:s');

    $room_type = null;

    if ($jury_lane > 0) {
        // Judging
        switch ($activity_type_detail) {
            case ID_ATD_C_WITH_TEAM:
            case ID_ATD_C_SCORING:
                $room_type = [
                    1 => ID_RT_C_LANE_1, 2 => ID_RT_C_LANE_2, 3 => ID_RT_C_LANE_3,
                    4 => ID_RT_C_LANE_4, 5 => ID_RT_C_LANE_5, 6 => ID_RT_C_LANE_6
                ][$jury_lane] ?? null;
                break;

            case ID_ATD_E_WITH_TEAM:
            case ID_ATD_E_SCORING:
                $room_type = [
                    1 => ID_RT_E_LANE_1, 2 => ID_RT_E_LANE_2, 3 => ID_RT_E_LANE_3,
                    4 => ID_RT_E_LANE_4, 5 => ID_RT_E_LANE_5, 6 => ID_RT_E_LANE_6
                ][$jury_lane] ?? null;
                break;

            case ID_ATD_LC_WITH_TEAM:
            case ID_ATD_LC_SCORING:
                $room_type = [
                    1 => ID_RT_LC_1, 2 => ID_RT_LC_2, 3 => ID_RT_LC_3,
                    4 => ID_RT_LC_4, 5 => ID_RT_LC_5, 6 => ID_RT_LC_6
                ][$jury_lane] ?? null;
                break;
        }

        DB::table('activity')->insertGetId([
            'activity_group' => $g_activity_group,
            'activity_type_detail' => $activity_type_detail,
            'start' => $start,
            'end' => $end,
            'room_type' => $room_type,
            'jury_lane' => $jury_lane,
            'jury_team' => $jury_team,
        ]);

    } elseif ($table_1 > 0) {
        // Robot Game
        $room_type = ID_RT_R_MATCH;

        DB::table('activity')->insertGetId([
            'activity_group' => $g_activity_group,
            'activity_type_detail' => $activity_type_detail,
            'start' => $start,
            'end' => $end,
            'room_type' => $room_type,
            'table_1' => $table_1,
            'table_1_team' => $table_1_team ?: null,
            'table_2' => $table_2,
            'table_2_team' => $table_2_team ?: null,
        ]);

    } else {
        // Everything else
        switch ($activity_type_detail) {
            case ID_ATD_OPENING:
                $room_type = ID_RT_OPENING;
                break;

            case ID_ATD_C_OPENING:
                $room_type = ID_RT_C_OPENING;
                break;

            case ID_ATD_E_OPENING:
                $room_type = ID_RT_E_OPENING;
                break;

            case ID_ATD_C_OPENING_DAY_1:
                $room_type = ID_RT_OPENING_DAY_1;
                break;
            case ID_ATD_C_OPENING_DAY_3:
                $room_type = ID_RT_OPENING_DAY_3;
                break;

            case ID_ATD_AWARDS:
                $room_type = ID_RT_AWARDS;
                break;

            case ID_ATD_C_AWARDS:
                $room_type = ID_RT_C_AWARDS;
                break;

                case ID_ATD_E_AWARDS:
                $room_type = ID_RT_E_AWARDS;
                break;

            case ID_ATD_C_PRESENTATIONS:
                $room_type = ID_RT_C_PRESENTATIONS;
                break;
            case ID_ATD_C_COACH_BRIEFING:
                $room_type = ID_RT_C_COACH;
                break;
            case ID_ATD_C_JUDGE_BRIEFING:
                $room_type = ID_RT_C_JUDGE_BRIEFING;
                break;
            case ID_ATD_C_JUDGE_BRIEFING_DAY_1:
                $room_type = ID_RT_C_JUDGE_BRIEFING_DAY_1;
                break;
            case ID_ATD_C_DELIBERATIONS:
                $room_type = ID_RT_C_JUDGE_DELIBERATIONS;
                break;

            case ID_ATD_R_REFEREE_BRIEFING:
            case ID_ATD_R_REFEREE_DEBRIEFING:
                $room_type = ID_RT_R_MATCH;
                break;

            case ID_ATD_E_COACH_BRIEFING:
                $room_type = ID_RT_E_COACH;
                break;
            case ID_ATD_E_JUDGE_BRIEFING:
                $room_type = ID_RT_E_JUDGE_BRIEFING;
                break;
            case ID_ATD_E_DELIBERATIONS:
                $room_type = ID_RT_E_JUDGE_DELIBERATIONS;
                break;

            case ID_ATD_C_LUNCH_BREAK:
                $room_type = ID_RT_LUNCH_BREAK;
                break;

            case ID_ATD_LC_JUDGE_BRIEFING:
            case ID_ATD_LC_DELIBERATIONS:
                $room_type = ID_RT_LC_JUDGE;
                break;
        }

        DB::table('activity')->insertGetId([
            'activity_group' => $g_activity_group,
            'activity_type_detail' => $activity_type_detail,
            'start' => $start,
            'end' => $end,
            'room_type' => $room_type,
        ]);
    }
}

function db_insert_free_activities()
{
    // Free blocks with fixed times
    $rows = DB::table('extra_block')
        ->select('id', 'first_program', 'start', 'end')
        ->where('plan', pp('g_plan'))
        ->where('active', 1) 
        ->whereNotNull('start')
        ->get();

    foreach ($rows as $row) {
        switch ((int)$row->first_program) {
            case ID_FP_CHALLENGE:
                $atd = ID_ATD_C_FREE;
                break;
            case ID_FP_EXPLORE:
                $atd = ID_ATD_E_FREE;
                break;
            default:
                $atd = ID_ATD_FREE;
        }

        $gid = db_insert_activity_group($atd);

        DB::table('activity')->insert([
            'activity_group' => $gid,
            'activity_type_detail' => $atd,
            'start' => $row->start,
            'end' => $row->end,
            'extra_block' => (int)$row->id,
        ]);
    }
}

// Insert an activity that delays the schedule
function g_insert_point($id, $duration)
{
    global $r_time;
    global $g_activity_group;

    $time = $r_time;  // Use r_time as current time to flexiby if c_time needs to be used in future situations
    
    $row = DB::table('extra_block')
        ->select('id', 'buffer_before', 'duration', 'buffer_after')
        ->where('plan', pp('g_plan'))
        ->where('insert_point', $id)
        ->where('active', 1) 
        ->first();

    if ($row) {
    
        db_insert_activity_group(ID_ATD_C_INSERTED);

        g_add_minutes($time, (int)$row->buffer_before);

        $time_start = clone $time;
        $time_end = clone $time;

        g_add_minutes($time_end, (int)$row->duration);

        $start = $time_start->format('Y-m-d H:i:s');
        $end = $time_end->format('Y-m-d H:i:s');

        DB::table('activity')->insertGetId([
            'activity_group' => $g_activity_group,
            'activity_type_detail' => ID_ATD_C_INSERTED,
            'start' => $start,
            'end' => $end,
            'extra_block' => (int)$row->id,
        ]);

        g_add_minutes($time, (int)$row->duration + (int)$row->buffer_after);
    
    } else {

        // No extra block defined, just the respective normal shift
        g_add_minutes($time, $duration);
        
    }
}
