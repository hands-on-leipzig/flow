<?php

require_once 'generator_functions.php';

// ***********************************************************************************
// Generator Main Function
// ***********************************************************************************

function g_generator($plan_id) {

    // ***********************************************************************************
    // Variable naming convention
    // ***********************************************************************************
    // "snake_case" is used
    // constant are UPPERCASE
    //
    // g_ global / generic
    // c_ FLL Challenge
    // j_ FLL Challenge judging
    // r_ FLL Challenge robot game
    // e_ FLL Explore
    // e1_ FLL Explore first group (morning)
    // e2_ FLL Explore second group (afternoon)
    // f_ Finale
    // lc_ Live Challenge

    // ***********************************************************************************
    // Get all data about the event and parameter values set by the organizer
    // ***********************************************************************************

    global $DEBUG;                      // Debug level. 0 = off

    // !!!! TODEL
    $DEBUG = 3;                       

    global $g_params;                   // Global array holding all parameters for the current plan
    $g_params = [];
    
    add_param("g_plan", $plan_id, "integer");   

    if($DEBUG >= 1){
        g_debug_log("g_plan");
        echo "<h2>Plan</h2>";
        echo "g_plan: " . gp("g_plan") . " <a href='https://dev.planning.hands-on-technology.org/generator/generator_day_plan.php?plan=" . gp("g_plan")  ."' target='plan'>Day Plan</a>"
             . " <a href='https://dev.planning.hands-on-technology.org/generator/extra/generator_show_plan_raw.php?plan=" . gp("g_plan")  ."' target='act'>Activities</a><br>";
    }

    // Core data that cannot be touched by the organizer

    db_get_from_plan();                 // Get the event ID from the plan   
    db_get_from_event();                // Get the date, number of days and flag for final from DB
    $g_event_date = new DateTime(gp("g_event_date"));    
        
    // Parameters that can be set by the organizer
    db_get_parameters();                // Get all parameters for the plan

    if($DEBUG >= 1){
        g_debug_log("e_mode");
        echo "<h2>Mode</h2>";
        echo "e mode: " . gp("e_mode") . "<br>";
    }

    // Derrived values that are calculated from the parameters

    if (gp("c_teams") > 0) {
     
        add_param("j_rounds", ceil(gp("c_teams") / gp("j_lanes")), "integer");                // Number of jury rounds in the schedule: Minimum 4 for 3x Robot Game + Test Round. Maximum 6 for fully utilized jury

        add_param("r_matches_per_round", ceil(gp("c_teams") / 2), "integer");                                          // need one match per two teams
        add_param("r_need_volunteer", gp("r_matches_per_round") != gp("c_teams") / 2, "boolean");                      // uneven number of teams --> "need a volunteer without scoring"
        add_param("r_asym", gp("r_tables") == 4 && ((gp("c_teams") % 4 == 1) || (gp("c_teams") % 4 == 2)), "boolean"); // 4 tables, but not multiple of 4 --> table 3/4 ends before 1/2);

    }

    if( gp("e1_teams") > 0) {
        add_param("e1_rounds", ceil(gp("e1_teams") / gp("e1_lanes")), "integer");             // Number of jury rounds in the schedule: 
    }
    
    if( gp("e2_teams") > 0) {
        add_param("e2_rounds", ceil(gp("e2_teams") / gp("e2_lanes")), "integer");             // Number of jury rounds in the schedule:      
    }

    // Other global variables

    global $g_activity_group;           // Same for the db ID of current activit group   


    // ***********************************************************************************
    // Definition of variables with scope only for this main function
    // ***********************************************************************************

    $c_day = 0;                         // [Temp] Current day of the event. 1 = first day, 2 = second day, etc.  
    
    global $c_time;
    $c_time = new DateTime();           // [Temp] Current time for FLL Challenge    

    global $j_time;
    $j_time = new DateTime();           // [Temp] Current time for judging in FLL Challenge       
    
    global $r_time;
    $r_time = new DateTime();           // Current time for robot game in FLL Challenge    
    
    global $e_time;
    $e_time = new DateTime();           // [Temp] Current time for judging in FLL Explore
    
    global $lc_time;
    $lc_time = new DateTime();          // [Temp] Current time for Live Challenge



    // ***********************************************************************************
    // Main
    // ***********************************************************************************

    /* 

    Main branch: FLL Challenge yes or no determined by the number of teams.

        If Challenge is present, Explore may be combined with it or stand-alone.
        If combined Explore actities are created in parallel with Challenge activities.
            Live Challenge modifies creation of the normale challenge day, because test rounds are on the day before.
            That day's activities are added after normal Challenge day is created.
        If stand-alone Challenge activities are created first, then Explore activities.

    Fundamental concepts FLL Challenge
     1. Number of teams and judging lanes defines number and timing of judging blocks in schedule
     2. Robot games is aligned to that

    Fundamental concepts FLL Explore
     1. Number of teams determines judging lanes

    */

    if (gp("c_teams") > 0) {

        // ***********************************************************************************
        // FLL Challenge (with our without FLL Explore)
        // ***********************************************************************************

        if ($DEBUG >= 1) {

            g_debug_log("c_teams");
            g_debug_log("j_lanes");
            g_debug_log("j_rounds");
            g_debug_log("r_tables");
            g_debug_log("r_robot_check");
            g_debug_log("r_matches_per_round");
            g_debug_log("r_need_volunteer");
            g_debug_log("r_asym");

            echo "<h2>Challenge</h2>";
            echo "c teams: " . gp("c_teams") . "<br>";
            echo "j lanes: " . gp("j_lanes") . "<br>";
            echo "j rounds: " . gp("j_rounds") . "<br>";
            echo "r tables: " . gp("r_tables") . "<br>";
            echo "r robot check: " . (gp("r_robot_check") ? 'Yes' : 'No') . "<br>";
            echo "RG matches per round: " . gp("r_matches_per_round") . "<br>";
            echo "RG need volunteer: " . (gp("r_need_volunteer") ? 'Yes' : 'No') . "<br>";
            echo "RG asymmetric: " . (gp("r_asym") ? 'Yes' : 'No') . "<br>";
        }

        // Check if the plan is supported. Die if not.
        db_check_supported_plan(
            ID_FP_CHALLENGE,
            gp("c_teams"), 
            gp("j_lanes"),  
            gp("r_tables"), 
        );

        // combine event date with start time of opening depending on the combination of FLL Challenge and FLL Explore

        // For a finale the main action is on day 2, while LC is on day 1
        if (gp("g_finale")) {

            // Save the day for Live Challenge
            $lc_time = clone $g_event_date;

            // combine event date with start time of day 1   
            list($hours, $minutes) = explode(':', gp("f_start_day_1"));   
            $lc_time->setTime((int)$hours, (int)$minutes);               

            // Add one day for the main action
            $g_event_date->modify('+1 day');

            // To simply branching in the challenge main day schedule, add an inidcator
            $c_day = 2; // Day 2 of the event

        } else {

            $c_day = 1; // Day 1 of the event
        }
   
        if (gp("e_mode") == ID_E_MORNING) {

            // FLL Challenge and Explore combined during the morning
            list($hours, $minutes) = explode(':', gp("g_start_opening"));

        } else {

            // FLL Challenge stand-alone
            list($hours, $minutes) = explode(':', gp("c_start_opening"));
        }

        $g_event_date->setTime((int)$hours, (int)$minutes);

        // Copy to variables
        $c_time = clone $g_event_date;
        $j_time = clone $g_event_date;
        $r_time = clone $g_event_date;
        $e_time = clone $g_event_date;


        // -----------------------------------------------------------------------------------
        // Challenge opening alone or joint with Explore 
        // -----------------------------------------------------------------------------------

        // Save time to schedule briefings before opening
        $t = clone $c_time; 

        if (gp("e_mode") == ID_E_MORNING) {
            // joint opening  
            
            if($DEBUG >= 2){
                echo "<h3>Opening - joint</h3>";
            }

            db_insert_activity_group(ID_ATD_OPENING);
            db_insert_activity(ID_ATD_OPENING, $c_time, gp("g_duration_opening"));        
            g_add_minutes($j_time, gp("g_duration_opening"));
            g_add_minutes($r_time, gp("g_duration_opening"));
            g_add_minutes($e_time, gp("g_duration_opening"));

        } else {
            // FLL Challenge only during the morning

            if($DEBUG >= 2){
                echo "<h3>Opening - Challenge only</h3>";
            }

            db_insert_activity_group(ID_ATD_C_OPENING);
            db_insert_activity(ID_ATD_C_OPENING, $c_time, gp("c_duration_opening"));
            g_add_minutes($j_time, gp("c_duration_opening"));
            g_add_minutes($r_time, gp("c_duration_opening"));
            
        }
              
        // -----------------------------------------------------------------------------------
        // Briefings before or after opening
        // -----------------------------------------------------------------------------------

        // Add briefings
        c_briefings($t, $c_day);
    
        // -----------------------------------------------------------------------------------
        // FLL Explore integrated during the morning 
        // -----------------------------------------------------------------------------------
        // Start with FLL Explore, because awards ceremony is between FLL Challenge robot game rounds
        // Therefore, FLL Explore timing needs to be calculate first!
        // Skip all, if there are not FLL Explore teams in the morning

        if (gp("e_mode") == ID_E_MORNING) {

            // Add briefings
            e_briefings($t, 1);
        
            if($DEBUG >= 1) {
                g_debug_log("e1_teams");
                g_debug_log("e1_lanes");
                g_debug_log("e1_rounds");

                echo "<h2>Explore - morning batch</h2>";
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

            // Full FLL Explore schedule for group 1
            e_judging(1);

            // Buffer before all judges meet for deliberations
            g_add_minutes($e_time, gp("e_ready_deliberations"));

            // Deliberations
            db_insert_activity_group(ID_ATD_E_DELIBERATIONS);

            db_insert_activity(ID_ATD_E_DELIBERATIONS, $e_time, gp("e1_duration_deliberations"), 0, 0);
            g_add_minutes($e_time, gp("e1_duration_deliberations"));

            // Awards for FLL Explore is next:
            // This would be the earliest time for FLL Explore awards
            // However, robot game may not have finished yet.
            // Thus the timing is determined further down 
        
        } else {

            if($DEBUG >= 1){
                echo "<h2>Explore - no morning batch</h2>";
            }

        } // FLL Explore morning batch

        // -----------------------------------------------------------------------------------
        // FLL Challenge
        // -----------------------------------------------------------------------------------
        // Robot Game and Judging run parallel in sync

        
        // Create the robot game match plan
        r_create_match_plan();

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Put the judging / robot game schedule together
        // -----------------------------------------------------------------------------------

        // Current time is the earlierst available time.
        $j_time_earliest = clone $j_time; // In block 1 judging starts immediately. No need to compare with robot game.

        $c_block = 0;
        $r_start_shift = 0;

        g_debug_timing("Los geht's", $c_block, $r_start_shift);

        // For judging team number are used in increasing order
        // $j_t is the first team in the block. The lane number is added to this.
        $j_t = 0;

        // Time for judging = how long will a team be away to judging and thus not available for robot game.
        $j_t4j = gp("j_duration_with_team") + gp("c_duration_transfer");

        // Create the blocks of judging with robot game aligned
        for ($c_block = 1; $c_block <= gp("j_rounds"); $c_block++) {

            // Adjust timing between judging and robot game

            // duration of one match: test round or normal
            if ($c_block == 1) {
                // Test round
                $r_duration = gp("r_duration_test_match");
            } else {
                $r_duration = gp("r_duration_match");
            }

            // Key concept 1: teams first in robot game go to juding in NEXT round
            //
            // available for judging = time from start of robot game round to being in front of judges' room
            // Calculate forward from start of the round:
            // 1 or 2 lanes = 1 match
            // 3 or 4 lanes = 2 matches
            // 5 or 5 lanes = 3 matches 

            // The calculation of a4j = available for judging is done after the start of robot game is determined below
            // Here the value of the last block is used.    

            // Delay judging if needed
            if (g_diff_in_minutes($j_time_earliest, $j_time) > 0) {
                $j_time = clone $j_time_earliest;
            }

            // Key concept 2: teams at judging are last in CURRENT robot game round
            //
            // time to match = when will be need the team for their robot game match?
            // The teams at judging go last in the current robot game round.
            // Calculate backwards from end of the round:
            // 1 or 2 lanes = 1 match
            // 3 or 4 lanes = 2 matches
            // 5 or 5 lanes = 3 matches 

            // number of matches before teams must be back from judging
            if ( $c_block == gp("j_rounds") && (gp("c_teams") % gp("j_lanes")) <> 0) {

                    // not all lanes filled in last round of judging
                    $r_mb = gp("r_matches_per_round") - ceil((gp("c_teams") % gp("j_lanes")) / 2);

                } else {
                    $r_mb = gp("r_matches_per_round") - ceil(gp("j_lanes") / 2);         
                }       

            // calculate time to START of match
            if (gp("r_tables") == 2) {
                // matches start in sequence
                $r_t2m = $r_mb * $r_duration;

            } else {
                // matches start alternating with respective delay between starts                  
                if ($r_mb % 2 === 0) {
                    $r_t2m = ($r_mb) / 2 * $r_duration;
                } else {   
                    $r_t2m = ($r_mb - 1) / 2 * $r_duration + gp("r_duration_next_start");
                }    
            }

            // Note: No need to consider robot check!
            // It delays the match start, but the teams have be there ealier for exactly the same amount of time.

            if ($DEBUG >= 99) {
                // echo "j_t4j: " . $j_t4j . "<br>";
                echo "r_mb : " . $r_mb . " // ";
                echo "r_t2m: " . $r_t2m . "<br>";
            }

            // Compare time away for judging and expectations from robotgame
            // Factor in the current difference between robot game and judging
            $r_start_shift = $j_t4j - $r_t2m - g_diff_in_minutes($r_time, $j_time);

            if ($DEBUG >= 99) {
                echo "j_t4j: " . $j_t4j . "<br>";
                echo "r_t2m: " . $r_t2m . "<br>";
                echo "r_start_shift: " . $r_start_shift . "<br>";
            }

            // Delay robot game if needed
            if ( $r_start_shift > 0) {
                g_add_minutes($r_time, $r_start_shift);
            }

            // Calculate a4j for concept 1
            // This data will be used above for the NEXT block

            // number of matches before all teams are ready to leave
            $r_mb = ceil(gp("j_lanes") / 2);

            // calculate time to END of the match            TODO same code as above only for different variable
            if (gp("r_tables") == 2) {
                // matches start in sequence
                $r_a4j = $r_mb * $r_duration;
            } else {
                // matches start alternating with respective delay between starts                  
                if ($r_mb % 2 === 0) {
                    $r_a4j = ($r_mb) / 2 * $r_duration;
                } else {   
                    $r_a4j = ($r_mb - 1) / 2 * $r_duration + gp("r_duration_next_start");
                }    
            }

            // Robot check shifts everything, but just once.
            if (gp("r_robot_check")) {
                $r_a4j += gp("r_duration_robot_check");
            }

            // Time for transfer from robot game to judges' room
            $r_a4j += gp("c_duration_transfer");

            // Store this as time object
            $j_time_earliest = clone $r_time;
            g_add_minutes($j_time_earliest, $r_a4j);


            // Now we are ready to create activities for robot game and then judging

            // judging including breaks
            j_judging_one_round($c_block, $j_t);

            // First team to start with in next block
            $j_t += gp("j_lanes");

            g_debug_timing("Nach Judging", $c_block, $r_start_shift);

            switch($c_block) {                // TODO for the Finale the mapping is different because TRs are on the day before

                case 1:
                    // First judging round runs parallel to RG test round, regardless of j_rounds
                    r_insert_one_round(0);
                    break;

                case 2:
                    if ( gp("j_rounds") == 4) {
                        r_insert_one_round(1);
                    } 
                    break;

                case 3:
                    if ( gp("j_rounds") == 4) {
                        r_insert_one_round(2);
  
                    } else {
                        r_insert_one_round(1);
                    }
                    break;

                case 4:
                    if ( gp("j_rounds") == 4) {
                        r_insert_one_round(3);
                    } else {
                        r_insert_one_round(2);
                    }
                    break;

                case 5:
                    r_insert_one_round(3);
                    break;

                case 6:

                    // No robot game left

            }

            g_debug_timing("Nach Robot Game", $c_block, $r_start_shift);
         
        }

/*   OLD CODE TODEL


                   
            // Four teams is extra specical. One round of robot game is too short to match to judging
            if (gp("c_teams") == 4) {
                switch ($c_block) {
                    case 2:
                        g_add_minutes($j_next, 25);
                        break;
                    case 4:
                        g_add_minutes($j_next, 10);
                        break;
                }
            }


*/

        g_debug_timing("Forschung Vorher", $c_block, $r_start_shift);

        // All judging and robot game actions done, but not necessarily in sync
        
        // No need to wait for judges filling sheets after teams have left
        $c_time = clone $j_time;
        g_add_minutes($c_time, - gp("j_duration_scoring"));

        // If RG is later, their time wins
        if ($r_time > $c_time) {
            $c_time = clone $r_time;
        }

        // FLL Challenge judging and RG is done. 

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Everything after judging / robot game rounds
        // -----------------------------------------------------------------------------------
        // 1 Judges go to deliberation
        // 2 Selected research on main stage
        // 3 followed by robot game finals
        // 4 awards
        //
        // 2 and 3 may be flipped

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Deliberations
        // -----------------------------------------------------------------------------------   

        // Move to judges main room
        g_add_minutes($j_time, gp("j_ready_deliberations"));

        // Deliberation
        db_insert_activity_group(ID_ATD_C_DELIBERATIONS);
        db_insert_activity(ID_ATD_C_DELIBERATIONS, $j_time, gp("j_duration_deliberations"));
        g_add_minutes($j_time, gp("j_duration_deliberations"));
        
        // -----------------------------------------------------------------------------------
        // Special for D-A-CH finale Siegen 2025: Move the next to another day. TODO
        // -----------------------------------------------------------------------------------

        if (gp("g_finale") && gp("g_days") == 3) {

            // Debriefing for referees
            g_add_minutes($r_time, gp("r_duration_break")); 
            db_insert_activity_group(ID_ATD_R_REFEREE_DEBRIEFING);
            db_insert_activity(ID_ATD_R_REFEREE_DEBRIEFING, $r_time, gp("r_duration_debriefing"), 0, 0, 0, 0, 0, 0);

            // Move to next day

            list($hours, $minutes) = explode(':', gp("f_start_opening_day_3"));
            $c_time->setTime((int)$hours, (int)$minutes); 
            $c_time->modify('+1 day');

            // Additional short referee briefing
            $t = clone $c_time;
            g_add_minutes($t, -1 * (gp("r_duration_briefing_2") + gp("c_ready_opening")));    
            db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);
            db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, $t, gp("r_duration_briefing_2"));    
            
            // Small opening day 3
            db_insert_activity_group(ID_ATD_C_OPENING_DAY_3);
            db_insert_activity(ID_ATD_C_OPENING_DAY_3, $c_time, gp("f_duration_opening_day_3"));
            g_add_minutes($c_time, gp("f_duration_opening_day_3"));  

            // Buffer between opening and first action for teams and judges
            g_add_minutes($c_time, gp("f_ready_action_day_3"));

        } 

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------      

        // Organizer may chose not to show any presentations.
        // They can also decide to show them at the end 

        if (gp("c_presentations") == 0 || gp("c_presentations_last")) {

            // No presentations at all or at the end. We run robot game finals first
            $r_time = clone $c_time;

            // Break for referees
            g_add_minutes($r_time, gp("r_duration_break"));

        } else {

            // Create inserted block or planned delay.
            g_insert_point(ID_IP_PRESENTATIONS);

            // Rearch presentations on stage
            c_presentations();

            // back to robot game
            g_add_minutes($c_time, gp("c_ready_presentations"));

            // As of now nothing runs in parallel to robot game, but we use r_time anyway to be more open for future changes
            $r_time = clone $c_time;   

        }

        // Additional 5 minutes to show who advances and for those teams to get ready
        g_add_minutes($r_time, gp("r_duration_results"));
        
        // -----------------------------------------------------------------------------------
        /// Robot-game final rounds
        // -----------------------------------------------------------------------------------    

        // Create inserted block or planned delay.
        g_insert_point(ID_IP_RG_FINAL_ROUNDS);
        
        // The DACH Finale is the only event running the round of best 16
        if(gp("g_finale") && gp("c_teams") >= 16) {
            r_final_round(16);
        }

        // Organizer can decide not to run round of best 8
        if((gp("g_finale") || gp("r_quarter_final")) && gp("c_teams") >= 8) {
            r_final_round(8);
        }

        // Semi finale is a must
        r_final_round(4);


        // Create inserted block or planned delay. 
        g_insert_point(ID_IP_RG_LAST_MATCHES);

        // Final matches
        r_final_round(2);   

        // back to only one action a time
        $c_time = clone $r_time;


        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------  

        if ( gp("c_presentations") > 0 && gp("c_presentations_last") ) {
            // Create inserted block or planned delay.
            g_insert_point(ID_IP_PRESENTATIONS);

            // Rearch presentations on stage
            c_presentations($c_time);
        }

        // -----------------------------------------------------------------------------------
        // Awards
        // -----------------------------------------------------------------------------------  

        // FLL Challenge
        // Deliberations might have taken longer, which is unlikely
        if (g_diff_in_minutes($j_time, $c_time) > 0) {
            $c_time = clone $j_time;
        } 

        // Create inserted block or planned delay.
        g_insert_point(ID_IP_AWARDS);

        // FLL Explore
        // Deliberations might have taken longer. Which is rather theroritical ...
        if (gp("e_mode") == ID_E_AFTERNOON && g_diff_in_minutes($e_time, $c_time) > 0 ) {                       
            $c_time = clone $e_time;
        } 
  
        // Awards

        if (gp("e_mode") == ID_E_AFTERNOON) {

            // Joint with Explore

            if($DEBUG >= 2) {
                echo "<h3>Awards - joint</h3>";
            }

            db_insert_activity_group(ID_ATD_AWARDS);
            db_insert_activity(ID_ATD_AWARDS, $c_time, gp("g_duration_awards") );
            g_add_minutes($c_time, gp("g_duration_awards") );

        } else {

            // Only Challenge

            if($DEBUG >= 2) {
                echo "<h3>Awards - Challenge only</h3>";
            }

            db_insert_activity_group(ID_ATD_C_AWARDS);
            db_insert_activity(ID_ATD_C_AWARDS, $c_time, gp("c_duration_awards"));
            g_add_minutes($c_time, gp("c_duration_awards"));

        }
        
        // -----------------------------------------------------------------------------------
        // FLL Explore decoupled from FLL Challenge
        // -----------------------------------------------------------------------------------

        if (gp("e_mode") == ID_E_DECOUPLED_MORNING || gp("e_mode") == ID_E_DECOUPLED_AFTERNOON || gp("e_mode") == ID_E_DECOUPLED_BOTH) {
            e_decoupled($g_event_date);
        } else {
            if($DEBUG >= 1){
                echo "<h2>Expore - no decoupled groups</h2>";
            }
        }

        // -----------------------------------------------------------------------------------
        // Finale has an extra day for Live Challenge and RG test rounds
        // -----------------------------------------------------------------------------------  
        
        if (gp("g_finale")) {  

             // Only for the D-A-CH final we run the Live Challenge
            // This is done on the day before the regular event
            // Teams get extra time with the same judges they meet during the regular event
            // In parallel test rounds for robot game are run

            g_finale(); // TODO: Add parameters for the finale

        } // Finale 

    } else {

        // ***********************************************************************************
        // FLL Explore without FLL Challenge
        // ***********************************************************************************

        if($DEBUG >= 1) {
            echo "<h2>FLL Explore only</h2>";
        }

        e_decoupled($g_event_date);


    } // End of main branch


    if($DEBUG >= 1) {
        echo "<h2>End of plan</h2>";
        echo "g plan: " . gp("g_plan") . " <a href='https://dev.planning.hands-on-technology.org/generator/generator_day_plan.php?plan=" . gp("g_plan")  ."' target='_new'>Day Plan</a>"
            . " <a href='https://dev.planning.hands-on-technology.org/generator/extra/generator_show_plan_raw.php?plan=" . gp("g_plan")  ."' target='_new'>Activities</a><br>";
        echo  $c_time->format('d.m.Y H:i') . " <br>";                      // TODO: max of e_time and c_time
    }

    // Add all free blocks. Timing does not matter, becuase these are parallel to other activities
    db_insert_free_activities();

} // function generator() 





?>
