<?php

require_once 'generator_db.php';

// ***********************************************************************************
// Explore functions
// ***********************************************************************************


// FLL Explore briefings
function e_briefings($t, $group) {

    global $DEBUG;
    global $e_time;

    if ($DEBUG >= 2) {
        echo "<h3>Explore - briefings</h3>";
    }


    // $t is the start of the opening ceremony

    if($group == 1) {
        $d_t = gp("e1_duration_briefing_t");
        $d_j = gp("e1_duration_briefing_j");
    } else {
        $d_t = gp("e2_duration_briefing_t");
        $d_j = gp("e2_duration_briefing_j");
    }


    // FLL Explore Coaches

    // Briefing is before opening on main day. No choice for organizer.

    db_insert_activity_group(ID_ATD_E_COACH_BRIEFING);
    db_insert_activity(ID_ATD_E_COACH_BRIEFING, g_shift_minutes($t, -1 * ($d_t + gp("e_ready_opening"))), $d_t);    

    // FLL Explore Judges

    if (!gp("e_briefing_after_opening_j")) {

        db_insert_activity(ID_ATD_E_JUDGE_BRIEFING,g_shift_minutes($t, -1 * ($d_j + gp("e_ready_opening"))), $d_j);    

    } else {

        g_add_minutes($e_time, gp("e_ready_briefing"));
        db_insert_activity(ID_ATD_E_JUDGE_BRIEFING, $e_time, $d_j); 
        
        // move time forward
        g_add_minutes($e_time, gp("j_duration_briefing"));   

    }

    // Buffer between opening (or briefing respectively) and first action for teams and judges
    g_add_minutes($e_time, gp("e_ready_action"));

}


// FLL Explore judging plan
function e_judging($group) {

    global $DEBUG;
    global $e_time;

    if ($DEBUG >= 2) {
        echo "<h3>Explore - judging</h3>";
    }
    
    // Build the plan

    // There is only one Activity Group for the full judging
    db_insert_activity_group(ID_ATD_E_JUDGING_PACKAGE);

    if ($group == 1) {
        $lanes = gp("e1_lanes");
        $rounds = gp("e1_rounds");
        $teams = gp("e1_teams");
        $offset = 0; // No offset for group 1
    } else {
        $lanes = gp("e2_lanes");
        $rounds = gp("e2_rounds");
        $teams = gp("e2_teams");
        $offset = gp("e1_lanes") ; // Numbering for lanes for group 2 continue from group 1 to avoid confusion  
    }


    // Let's build the rounds
    for ($e_r = 1; $e_r <= $rounds; $e_r++) {
    
        // Judges with team
        for ($e_l = 1; $e_l <= $lanes; $e_l++) {
            $e_t = ($e_l - 1) * $rounds + $e_r;

            // Not all lanes may be full
            if ($e_t <= $teams) {
                db_insert_activity(ID_ATD_E_WITH_TEAM, $e_time, gp("e_duration_with_team"), $e_l + $offset, $e_t);
            }
        
        }
        g_add_minutes($e_time, gp("e_duration_with_team"));        

        // Judges alone do the scoring

        for ($e_l = 1; $e_l <= $lanes; $e_l++) {
            $e_t = ($e_l - 1) * $rounds + $e_r;

            // Not all lanes may be full
            if ($e_t <= $teams) {
                db_insert_activity(ID_ATD_E_SCORING, $e_time, gp("e_duration_scoring"), $e_l + $offset, $e_t);
            }
        }
        
        g_add_minutes($e_time, gp("e_duration_scoring"));

        // Short break, but not after last team
        if ($e_r < $rounds) {
            g_add_minutes($e_time, gp("e_duration_break"));
        }
    }

}


// FLL Explore decoupled
//
// This is used for either Explore parallel to Challenge or for Explore standalone
//
function e_decoupled($g_event_date) {

    global $DEBUG;
    global $e_time;

    if (gp("e1_teams") > 0) {

        if($DEBUG >= 1) {
            echo "<h2>Explore decoupled - group 1</h2>";
            echo "e1 teams: " . gp("e1_teams") . "<br>";
            echo "e1 lanes: " . gp("e1_lanes") . "<br>";
            echo "e1 rounds: " . gp("e1_rounds") . "<br>";
            echo "<br>";
        }

        // Check if the plan is supported. Die if not.
        db_check_supported_plan(
            ID_FP_EXPLORE,
            gp("e1_teams"), 
            gp("e1_lanes") 
        );

        // Get date and time for FLL Explore
        $e_time = clone $g_event_date;
        list($hours, $minutes) = explode(':', gp("e1_start_opening"));
        $e_time->setTime((int)$hours, (int)$minutes); 

        // Catch the time for the opening
        $t = clone $e_time;

        // Opening   
        db_insert_activity_group(ID_ATD_E_OPENING);
        db_insert_activity(ID_ATD_E_OPENING, $e_time, gp("e1_duration_opening"));
        g_add_minutes($e_time, gp("e1_duration_opening"));
        
        // Briefings for FLL Explore independent group 1
        e_briefings($t, 1); // Briefings

        // Judging
        e_judging(1);

        // Buffer before all judges meet for deliberations
        g_add_minutes($e_time, gp("e_ready_deliberations"));

        // Deliberations
        db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
        db_insert_activity(ID_ATD_E_DELIBERATIONS, $e_time, gp("e1_duration_deliberations"), 0, 0);
        g_add_minutes($e_time, gp("e1_duration_deliberations"));

        // Awards

        // Get ready e.g. judges get on stage
        g_add_minutes($e_time, gp("e_ready_awards"));

        // Add FLL Explore Awards
        db_insert_activity_group(ID_ATD_E_AWARDS);
        db_insert_activity(ID_ATD_E_AWARDS, $e_time, gp("e1_duration_awards"));
        g_add_minutes($e_time, gp("e1_duration_awards"));
    
    } // e1_teams > 0


    if(gp("e2_teams") > 0) {

        if($DEBUG >= 1) {
            echo "<h2>Explore decoupled - group 2</h2>";
            echo "e2 teams: " . gp("e2_teams") . "<br>";
            echo "e2 lanes: " . gp("e2_lanes") . "<br>";
            echo "e2 rounds: " . gp("e2_rounds") . "<br>";
            echo "<br>";
        }

        // Check if the plan is supported. Die if not.
        db_check_supported_plan(
            ID_FP_EXPLORE,
            gp("e2_teams"), 
            gp("e2_lanes") 
        );

        // Get date and time for FLL Explore
        $e_time = clone $g_event_date;
        list($hours, $minutes) = explode(':', gp("e2_start_opening"));
        $e_time->setTime((int)$hours, (int)$minutes); 

        // Catch the time for the opening
        $t = clone $e_time;

        // Opening   
        db_insert_activity_group(ID_ATD_E_OPENING);
        db_insert_activity(ID_ATD_E_OPENING, $e_time, gp("e2_duration_opening"));
        g_add_minutes($e_time, gp("e2_duration_opening"));
        
        // Briefings for FLL Explore independent group 2
        e_briefings($t, 2); // Briefings

        // Judging
        e_judging(2);

        // Buffer before all judges meet for deliberations
        g_add_minutes($e_time, gp("e_ready_deliberations"));

        // Deliberations
        db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
        db_insert_activity(ID_ATD_E_DELIBERATIONS, $e_time, gp("e2_duration_deliberations"), 0, 0);
        g_add_minutes($e_time, gp("e2_duration_deliberations"));

        // Awards

        // Get ready e.g. judges get on stage
        g_add_minutes($e_time, gp("e_ready_awards"));

        // Add FLL Explore Awards
        db_insert_activity_group(ID_ATD_E_AWARDS);
        db_insert_activity(ID_ATD_E_AWARDS, $e_time, gp("e2_duration_awards"));
        g_add_minutes($e_time, gp("e2_duration_awards"));
    
    } else {

        if($DEBUG >= 1){
            echo "<h2>Explore decoupled - no group 2</h2>";
        }
    }

} 

// Integration of Explore in Challenge lunch break.

function e_integrated() {

    global $DEBUG;
    global $r_time;
    global $e_time; 

    // handle integrated Explore activities 

    switch(gp("e_mode")) {

        case ID_E_MORNING:
            // FLL Explore morning batch > awards ceremony

            // Need to postpone awards?
            if (g_diff_in_minutes($r_time, $e_time) > 0) {
                $e_time = clone $r_time;
            }

            // Get ready e.g. judges get on stage, RG teams and referees move away ... 
            g_add_minutes($e_time, gp("e_ready_awards"));                                           // TODO different parameters "to e and back to c"

            // Add FLL Explore Awards
            db_insert_activity_group(ID_ATD_E_AWARDS);
            db_insert_activity(ID_ATD_E_AWARDS, $e_time, gp("e1_duration_awards"));
            g_add_minutes($e_time, gp("e1_duration_awards"));
            
            // Earliest to go back to Robot Game same buffer as before awards
            g_add_minutes($e_time, gp("e_ready_awards"));

            // FLL Explore morning batch is over here.

            // Robot Game can continue afterwards
            $r_time = clone $e_time;
            // Buffer to get Explore people out of the way
            g_add_minutes($r_time, gp("e_ready_awards"));          // TODO different parameters "to e and back to c"

            if($DEBUG >= 1){
                echo "<h2>Explore - no afternoon batch</h2>";
            }
            break; 

        case ID_E_AFTERNOON:
            // FLL Explore afternoon batch > opening, briefings, judging

            if($DEBUG >= 1) {
                echo "<h2>Explore - afternoon batch</h2>";
                echo "e2 teams: " . gp("e2_teams") . "<br>";
                echo "e2 lanes: " . gp("e2_lanes") . "<br>";
                echo "e2 rounds: " . gp("e2_rounds") . "<br>";
                echo "<br>";
            }               

            // Check if the plan is supported. Die if not.
            db_check_supported_plan(
                ID_FP_EXPLORE,
                gp("e2_teams"), 
                gp("e2_lanes") 
            );

            //TODO this should be as LATE as possible to keep the day short for the younger kids

            // start as early as robot games allows
            $e_time = clone $r_time;

            // Get ready e.g. judges get on stage, RG teams and refree move away ... 
            g_add_minutes($e_time, gp("e_ready_awards"));          // TODO different parameters "to e and back to c"
            
            // Capture the time for the opening
            $t = clone $e_time;

            // FLL Explore afternoon Opening
            db_insert_activity_group(ID_ATD_E_OPENING);
            db_insert_activity(ID_ATD_E_OPENING, $e_time, gp("e2_duration_opening"));
            g_add_minutes($e_time, gp("e2_duration_opening"));

            // Robot Game can continue afterwards
            $r_time = clone $e_time;
            // Buffer to get Explore people out of the way
            g_add_minutes($r_time, gp("e_ready_awards"));          // TODO different parameters "to e and back to c"

            e_briefings($t, 2); // Briefings for Explore afternoon batch

            e_judging(2); // Full FLL Explore schedule for afternoon batch

            // Buffer before all judges meet for deliberations
            g_add_minutes($e_time, gp("e_ready_deliberations"));

            // Deliberations
            db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
            db_insert_activity(ID_ATD_E_DELIBERATIONS, $e_time, gp("e2_duration_deliberations"), 0, 0);
            g_add_minutes($e_time, gp("e2_duration_deliberations"));

            // FLL Explore afternoon is done             
            // Wait for the joint awards ceremony
            break;

        case ID_E_DECOUPLED_MORNING:
        case ID_E_DECOUPLED_AFTERNOON:
        case ID_E_DECOUPLED_BOTH:

            if($DEBUG >= 1){
                echo "<h2>Explore - no afternoon batch</h2>";
            }
            break;
    }

}

?>
