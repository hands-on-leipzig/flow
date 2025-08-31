<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// <-- new

// ***********************************************************************************
// DB abstraction layer (now using Laravel DB facade)
// ***********************************************************************************

/**
 * Legacy no-op: leave in place so legacy code can still call it.
 */
function db_connect_persistent()
{
    // No-op. We rely on Laravel's DB connection pool.
    // Keep $g_db for legacy compatibility if any function checks it exists.
    global $g_db;
    $g_db = true;
}

/**
 * Legacy no-op: leave in place so legacy code can still call it.
 */
function db_disconnect_persistent()
{
    // No-op. Let Laravel manage connections.
}

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
define('ID_ATD_C_LUNCH_TEAM', 22);
define('ID_ATD_C_LUNCH_JUDGE', 23);
define('ID_ATD_C_COACH_BRIEFING', 35);
define('ID_ATD_C_JUDGE_BRIEFING_DAY_1', 55);

define('ID_ATD_C_LUNCH_VISITOR', 25);
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
define('ID_ATD_R_LUNCH_REFEREE', 24);
define('ID_ATD_R_LUNCH_ROBOT_CHECK', 25);
define('ID_ATD_R_FINAL_16', 45);
define('ID_ATD_R_REFEREE_DEBRIEFING', 46);

// FLL Explore
define('ID_ATD_E_OPENING', 5);
define('ID_ATD_E_JUDGING_PACKAGE', 4);
define('ID_ATD_E_WITH_TEAM', 1);
define('ID_ATD_E_SCORING', 2);
define('ID_ATD_E_DELIBERATIONS', 3);
define('ID_ATD_E_LUNCH', 26);
define('ID_ATD_E_LUNCH_TEAM', 27);
define('ID_ATD_E_LUNCH_JUDGE', 28);
define('ID_ATD_E_LUNCH_VISITOR', 29);
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
define('ID_IP_RG_1', 6);
define('ID_IP_RG_2', 7);
define('ID_IP_RG_3', 8);
define('ID_IP_PRESENTATIONS', 1);
define('ID_IP_RG_FINAL_ROUNDS', 2);
define('ID_IP_RG_LAST_MATCHES', 4);
define('ID_IP_AWARDS', 3);

// IDs from m_room_type
define('ID_RT_R_MATCH', 1);
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
define('ID_RT_C_JUDGE_BRIEFING', 15);
define('ID_RT_E_JUDGE_BRIEFING', 16);
define('ID_RT_C_COACH', 17);
define('ID_RT_E_COACH', 18);
define('ID_RT_LUNCH_TEAM', 19);
define('ID_RT_LUNCH_VOLUNTEER', 20);
define('ID_RT_LUNCH_VISITOR', 21);
define('ID_RT_E_EXIBITION', 22);
define('ID_RT_AWARDS', 23);
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

/**
 * Load all parameters for a given plan into global $g_params.
 * (signatures preserved; uses DB facade)
 */
function db_get_parameters()
{
    global $DEBUG, $g_params;

    // 1) base parameters (with type + default value)
    $base = [];
    $rows = DB::table('m_parameter')->select('id', 'name', 'type', 'value')->get();
    foreach ($rows as $row) {
        $base[$row->id] = [
            'name' => $row->name,
            'type' => $row->type,
            'value' => cast_value($row->value, $row->type),
        ];
    }

    // 2) overlay plan-specific values
    $planId = gp('g_plan');
    $over = DB::table('plan_param_value')
        ->select('parameter', 'set_value')
        ->where('plan', $planId)
        ->get();

    foreach ($over as $r) {
        if (isset($base[$r->parameter])) {
            $base[$r->parameter]['value'] = cast_value($r->set_value, $base[$r->parameter]['type']);
        }
    }

    // 3) fill $g_params keyed by name
    foreach ($base as $p) {
        $g_params[$p['name']] = [
            'value' => $p['value'],
            'type' => $p['type'],
        ];
    }

    if (($DEBUG ?? 0) >= 4) {
        ksort($g_params);
        echo "<h3>Parameter</h3><pre>";
        foreach ($g_params as $name => $data) {
            $val = var_export($data['value'], true);
            echo sprintf("%-30s | %-8s | %s\n", $name, $data['type'], $val);
        }
        echo "</pre>";
    }
}

function cast_value($rawValue, $type)
{
    if ($rawValue === null) return null;

    switch ($type) {
        case 'integer':
            return (int)$rawValue;
        case 'decimal':
            return (float)$rawValue;
        case 'boolean':
            return ($rawValue == '1');
        case 'time':
        case 'date':
            return $rawValue; // keep string
        default:
            return (string)$rawValue;
    }
}

function gp($name)
{
    global $g_params;
    if (!isset($g_params[$name])) {
        die("Error: Parameter '{$name}' not found.");
    }
    return $g_params[$name]['value'];
}

function add_param($name, $value, $type = 'string')
{
    global $g_params;
    $g_params[$name] = ['value' => $value, 'type' => $type];
}

function db_get_from_plan()
{
    global $DEBUG;

    $planId = gp('g_plan');
    $row = DB::table('plan')->select('event')->where('id', $planId)->first();

    if ($row) {
        add_param('g_event', (int)$row->event, 'integer');
        if (($DEBUG ?? 0) >= 3) {
            echo "<h4>From plan</h4>";
            echo "g event: " . gp("g_event");
        }
    } else {
        echo "<h3>No data found for plan ID " . gp("g_plan") . "</h3>";
    }
}

function db_get_from_event()
{
    global $DEBUG;

    $row = DB::table('event')->select('date', 'days', 'level')->where('id', gp('g_event'))->first();

    $date = $row->date ?? '';
    $days = (int)($row->days ?? 0);
    $level = (int)($row->level ?? 0);

    add_param('g_event_date', $date, 'date');
    add_param('g_days', $days, 'integer');
    add_param('g_finale', $level === 3, 'boolean');

    if (($DEBUG ?? 0) >= 3) {
        echo "<h4>From event</h4>";
        echo "g event date: " . gp("g_event_date") . "<br>";
        echo "g days: " . gp("g_days") . "<br>";
        echo "g finale: " . (gp("g_finale") ? 'true' : 'false') . "<br>";
    }
}

function db_check_supported_plan($first_program, $teams, $lanes, $tables = NULL)
{
    $q = DB::table('m_supported_plan')
        ->where('first_program', $first_program)
        ->where('teams', $teams)
        ->where('lanes', $lanes);

    if ($tables === NULL) {
        $q->whereNull('tables');
    } else {
        $q->where('tables', $tables);
    }

    if (!$q->exists()) {
        die("No supported plan found for the given parameters.");
    }
}

function db_insert_activity_group($activity_type_detail)
{
    global $g_activity_group;

    $id = DB::table('activity_group')->insertGetId([
        'plan' => gp('g_plan'),
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
            case ID_ATD_C_OPENING:
            case ID_ATD_E_OPENING:
                $room_type = ID_RT_OPENING;
                break;

            case ID_ATD_C_OPENING_DAY_1:
                $room_type = ID_RT_OPENING_DAY_1;
                break;
            case ID_ATD_C_OPENING_DAY_3:
                $room_type = ID_RT_OPENING_DAY_3;
                break;

            case ID_ATD_AWARDS:
            case ID_ATD_C_AWARDS:
            case ID_ATD_E_AWARDS:
                $room_type = ID_RT_AWARDS;
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

            case ID_ATD_C_LUNCH_TEAM:
            case ID_ATD_E_LUNCH_TEAM:
                $room_type = ID_RT_LUNCH_TEAM;
                break;

            case ID_ATD_C_LUNCH_VISITOR:
            case ID_ATD_E_LUNCH_VISITOR:
                $room_type = ID_RT_LUNCH_VISITOR;
                break;

            case ID_ATD_C_LUNCH_JUDGE:
            case ID_ATD_R_LUNCH_REFEREE:
            case ID_ATD_E_LUNCH_JUDGE:
                $room_type = ID_RT_LUNCH_VOLUNTEER;
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

function db_get_duration_inserted_activity($insert_point)
{
    $row = DB::table('extra_block')
        ->select('buffer_before', 'duration', 'buffer_after')
        ->where('plan', gp('g_plan'))
        ->where('insert_point', $insert_point)
        ->first();

    if (!$row) return 0;

    return (int)$row->buffer_before + (int)$row->duration + (int)$row->buffer_after;
}

function db_insert_extra_activity($activity_type_detail, $time, $insert_point)
{
    global $g_activity_group;

    // Read extra block
    $row = DB::table('extra_block')
        ->select('id', 'buffer_before', 'duration', 'buffer_after')
        ->where('plan', gp('g_plan'))
        ->where('insert_point', $insert_point)
        ->first();

    if (!$row) return;

    // Read room_type from m_insert_point
    $insert_point_row = DB::table('m_insert_point')
        ->select('room_type')
        ->where('id', $insert_point)
        ->first();

    $room_type = $insert_point_row ? $insert_point_row->room_type : null;

    $time_start = clone $time;
    g_add_minutes($time_start, (int)$row->buffer_before);

    $time_end = clone $time_start;
    g_add_minutes($time_end, (int)$row->duration);

    $start = $time_start->format('Y-m-d H:i:s');
    $end = $time_end->format('Y-m-d H:i:s');

    DB::table('activity')->insertGetId([
        'activity_group' => $g_activity_group,
        'activity_type_detail' => $activity_type_detail,
        'start' => $start,
        'end' => $end,
        'extra_block' => (int)$row->id,
        'room_type' => $room_type,
    ]);
}

function db_insert_free_activities()
{
    // Free blocks with fixed times
    $rows = DB::table('extra_block')
        ->select('id', 'first_program', 'start', 'end')
        ->where('plan', gp('g_plan'))
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
function g_insert_point($id)
{
    global $c_time, $r_time;

    $time = new DateTime();

    switch ($id) {
        case ID_IP_RG_1:
        case ID_IP_RG_2:
        case ID_IP_RG_3:
        case ID_IP_RG_FINAL_ROUNDS:
        case ID_IP_RG_LAST_MATCHES:    
            $time = $r_time;
            break;

        case ID_IP_PRESENTATIONS:
        case ID_IP_AWARDS:
            $time = $c_time;
            break;
    }

    $duration = db_get_duration_inserted_activity($id);

    if ($duration > 0) {
        db_insert_activity_group(ID_ATD_C_INSERTED);
        db_insert_extra_activity(ID_ATD_C_INSERTED, $time, $id);
        g_add_minutes($time, $duration);
    } else {
        switch ($id) {
            case ID_IP_RG_1:
            case ID_IP_RG_3:
                g_add_minutes($time, gp('r_duration_break'));
                break;

            case ID_IP_RG_2:
                g_add_minutes($time, gp('r_duration_lunch'));
                break;

            case ID_IP_PRESENTATIONS:
                g_add_minutes($time, gp('c_ready_presentations'));
                break;

            case ID_IP_AWARDS:
                g_add_minutes($time, gp('c_ready_awards'));
                break;
        }
    }
}
