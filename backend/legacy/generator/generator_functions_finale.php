<?php

require_once 'generator_db.php';


// ***********************************************************************************
// Finale functions
// ***********************************************************************************


function g_finale() {                   // TODO: Add parameters for the finale

    global $DEBUG;

    // -----------------
    // Live Challenge
    // -----------------

    // Default is a short break between briefings and first round.
    // It may be replaced by a custom slot for light weight opening ceremony
    
    // LC day was already set at the beginning of this script

    // Adjust the time
    list($hours, $minutes) = explode(':', gp("f_start_opening_day_1"));
    $lc_time->setTime((int)$hours, (int)$minutes); 

    // Continue going back in time for the briefings

    // LC Judges
    $t = clone $lc_time;
    g_add_minutes($t, -1 * (gp("lc_duration_briefing") + gp("c_ready_opening")));
    db_insert_activity_group(ID_ATD_LC_JUDGE_BRIEFING);
    db_insert_activity(ID_ATD_LC_JUDGE_BRIEFING, $t, gp("lc_duration_briefing"));    

    // FLL Challenge Coaches
    $t = clone $lc_time;
    g_add_minutes($t, -1 * (gp("c_duration_briefing") + gp("c_ready_opening")));
    db_insert_activity_group(ID_ATD_C_COACH_BRIEFING);
    db_insert_activity(ID_ATD_C_COACH_BRIEFING, $t, gp("c_duration_briefing"));    

    // FLL Challenge Referees
    $t = clone $lc_time;
    g_add_minutes($t, -1 * (gp("r_duration_briefing") + gp("c_ready_opening")));
    db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);
    db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, $t, gp("r_duration_briefing"));    

    // Small opening day 1
    db_insert_activity_group(ID_ATD_C_OPENING_DAY_1);
    db_insert_activity(ID_ATD_C_OPENING_DAY_1, $lc_time, gp("f_duration_opening_day_1"));
    g_add_minutes($lc_time, gp("f_duration_opening_day_1"));  

    // Buffer between opening and first action for teams and judges
    g_add_minutes($lc_time, gp("f_ready_action_day_1"));
    
    // Same start for RG test rounds
    $r_time = clone $lc_time;

    // For judging team number are used in increasing order
    // $j_t is the first team in the block. The lane number is added to this.
    $j_t = 0;

    // Now create the blocks of LC judging with robot game test rounds aligned

    for ($c_block = 1; $c_block <= gp("j_rounds"); $c_block++) {

        //---
        // LC Judging and Robot Game test rounds
        //---

        db_insert_activity_group(ID_ATD_LC_JUDGING_PACKAGE);

        // with team
        for ($j_l = 1; $j_l <= gp("j_lanes"); $j_l++) {
            
            // Not all lanes might be full
            if ($j_t + $j_l <= gp("c_teams")) {
                
                db_insert_activity(ID_ATD_LC_WITH_TEAM, $lc_time, gp("lc_duration_with_team"), $j_l, $j_t + $j_l, 0, 0, 0, 0);
                
            }
        }
        g_add_minutes($lc_time, gp("lc_duration_with_team"));

        // scoring without team
        for ($j_l = 1; $j_l <= gp("j_lanes"); $j_l++) {
            
            // Not all lanes might be full
            if ($j_t + $j_l <= gp("c_teams")) {

                db_insert_activity(ID_ATD_LC_SCORING, $lc_time, gp("lc_duration_scoring"), $j_l, $j_t + $j_l, 0, 0, 0, 0);
            }
        }
        g_add_minutes($lc_time, gp("lc_duration_scoring"));

        // First team to start with in next block
        $j_t += gp("j_lanes");

        // ***
        // Breaks
        // ***
        
        if ($c_block < gp("j_rounds")) {

            // Judges
            g_add_minutes($lc_time, gp("lc_duration_break"));
        }

    } // for block ...

    // Ready for deliberations
    g_add_minutes($lc_time, gp("lc_ready_deliberations"));

    // Deliberation
    db_insert_activity_group(ID_ATD_LC_DELIBERATIONS);
    db_insert_activity(ID_ATD_LC_DELIBERATIONS, $lc_time, gp("lc_duration_deliberations"));
    g_add_minutes($lc_time, gp("lc_duration_deliberations"));

    // Optional judge briefing to get ready for the main day
    // If duration is set to 0, it will be skipped

    if (gp("f_duration_briefing_day_1") > 0) {

        $c_time = clone $lc_time;

        // Time to switch from LC to FLL Challenge briefing
        g_add_minutes($c_time, gp("f_ready_briefing_day_1"));

        db_insert_activity_group(ID_ATD_C_JUDGE_BRIEFING_DAY_1);
        db_insert_activity(ID_ATD_C_JUDGE_BRIEFING_DAY_1, $c_time, gp("f_duration_briefing_day_1"), 0, 0, 0, 0, 0, 0);
    }


    // -----------------
    // RG test rounds
    // -----------------

    // Manually optimized to fit LC. Each team will be at their RG1 table and at a different table
    // There are two test rounds for each team. Breaks for referees are factored in

    // 4 tables = alternating between tables
    // robot check is always on
    // In test round with four tables, match starts alternate between 5 and 10 minutes

    // TR 1 of 2
    db_insert_activity_group(ID_ATD_R_ROUND_TEST);

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 13, 2, 16, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 15, 4, 18, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));
    
    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 14, 2, 17, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    // -

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 22, 4, 23, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 19, 2, 20, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 24, 4, 21, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));


    g_add_minutes($r_time, gp("lc_duration_break")); // Same break as lC judges to keep the flow in sync


    r_insert_one_match($r_time, gp("r_duration_test_match"), 1,  4, 2,  5, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3,  2, 4,  3, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));
    
    r_insert_one_match($r_time, gp("r_duration_test_match"), 1,  6, 2,  1, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    // -


    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 26, 4, 29, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 25, 2, 27, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 30, 4, 28, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    // 

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 10, 2, 11, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3,  8, 4,  9, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));
    
    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 12, 2,  7, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));


    g_add_minutes($r_time, 2 * gp("lc_duration_break") + 5); // Some fine-tuning needed

    // TR 2 of 2
    db_insert_activity_group(ID_ATD_R_ROUND_TEST);


    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 13, 4, 14, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 15, 2, 16, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 17, 4, 18, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    // -

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1,  1, 2,  2, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3,  3, 4,  4, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));
    
    r_insert_one_match($r_time, gp("r_duration_test_match"), 1,  5, 2,  6, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    
    g_add_minutes($r_time, gp("lc_duration_break")); // Same break as lC judges to keep the flow in sync


    // -

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 21, 4, 22, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 23, 2, 24, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 19, 4, 20, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    // -

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1,  7, 2,  8, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3,  9, 4, 10, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));
    
    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 11, 2, 12, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    // -

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 25, 4, 26, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 1, 27, 2, 28, true);
    g_add_minutes($r_time, gp("r_duration_next_start"));

    r_insert_one_match($r_time, gp("r_duration_test_match"), 3, 29, 4, 30, true);
    g_add_minutes($r_time, gp("r_duration_test_match") - gp("r_duration_next_start"));

    // Debriefing for referees
    g_add_minutes($r_time, gp("r_duration_robot_check")); 
    g_add_minutes($r_time, gp("r_duration_break")); 
    db_insert_activity_group(ID_ATD_R_REFEREE_DEBRIEFING);
    db_insert_activity(ID_ATD_R_REFEREE_DEBRIEFING, $r_time, gp("r_duration_debriefing"), 0, 0, 0, 0, 0, 0);

}

?>
