<?php
use Illuminate\Support\Facades\Log;
require_once 'generator_db.php';



// ***********************************************************************************
// Challenge functions
// ***********************************************************************************


// ***********************************************************************************
// Robot Game match plan
// ***********************************************************************************


function r_get_next_team(&$team) {

    // Get the next team with lower number
    // When 0 is reached cycle to max number
    // Include volunteer team if needed

    $team--;

    if ($team == 0) {
        if (pp("r_need_volunteer")) {
            $team = pp("c_teams") + 1; // Volunteer team
        } else {
            $team = pp("c_teams");
        }
    }
} 

function r_add_match($round, $match, $team_1, $team_2, $table_1 = 1, $table_2 = 2) {

    // Add a match to the match plan array 
    // Table default to 1 and 2 as this the most common use case

    global $r_match_plan;

    $r_match_plan[] = [
        'round' => $round,
        'match' => $match,
        'table_1' => $table_1,  // Default to 1 if not provided
        'table_2' => $table_2,  // Default to 2 if not provided
        'team_1' => ($team_1 > pp("c_teams")) ? 0 : $team_1,      // Change volunteer from pp("c_teams")+1 to 0
        'team_2' => ($team_2 > pp("c_teams")) ? 0 : $team_2,      // Change volunteer from pp("c_teams")+1 to 0
    ];
}
    
function r_add_test_match($m0, $m1) {

    // Build matches in TR from RG1
    
    global $r_match_plan;

    // Loop through the match plan to find the specific match in round 1 with match number $m1
    foreach ($r_match_plan as $item) {
        if ($item['round'] == 1 && $item['match'] == $m1) {

            // Create a new entry by copying values from the found match
            $new_match = [
                'round' => 0,                     // Set the new round to 0
                'match' => $m0,                   // Set the match number to $m0
                'table_1' => $item['table_1'],    // Copy table_1 from the existing match
                'table_2' => $item['table_2'],    // Copy table_2 from the existing match
                'team_1' => ($item['team_1'] > pp("c_teams")) ? 0 : $item['team_1'],      // Copy team_1 from the existing match. Volunteer not needed.
                'team_2' => ($item['team_2'] > pp("c_teams")) ? 0 : $item['team_2'],      // Copy team_2 from the existing match. Volunteer not needed.
            ];

            // Add the new match to the match plan array
            $r_match_plan[] = $new_match;
            
            // As only one match is added, break the loop
            break;
        }
    }
}
 
function r_create_match_plan() {

    // Create the robot game match plan regardless of the number of tables and timing

    global $DEBUG_RG;
    global $r_match_plan;
 
    $r_match_plan = []; // Initialize the match plan array

    // Generate rounds 1 to 3 matching the judging round
    // Then build the test round from round 1
    // - preserve the table assignments
    // - shift matches "backwards" to fit judging round 1

    for ($round = 0; $round <= 3; $round++) {
        
        // Fill the lines from bottom to top
        // Start with adding teams that are scheduled for judging first. They will be last in the RG round.
        // Add all other teams in decreasing order. Flip from 0 to highest team-number.

       
        if ($round == 0) {
        
             // TR is easy: Teams starting with judging are last in TR
            $team = pp("j_lanes");
        
        } else {
         
            switch (pp("j_rounds")) {
        
                case 4:
                    if ($round < 3) {
                        $team = pp("j_lanes") * ($round + 1);
                    } else {
                        // not all lanes may be filled in last judging round
                        $team = pp("c_teams");
                    }
                    break;   

                case 5:
                    if ($round < 3) {
                        $team = pp("j_lanes") * ($round + 2);
                    } else {
                        // not all lanes may be filled in last judging round
                        $team = pp("c_teams");
                    }
                    break;   

                case 6:
                    $team = pp("j_lanes") * ($round + 2);
                    
                    // not all lanes may be filled in last judging round, 
                    // but that does not matter with six rounds, because robot game is aligned with judging 5
                    
            } 

            // If we have an odd number of teams, we start with the empty team                     
            if ($team == pp("c_teams") && pp("r_need_volunteer")) {
                $team = pp("c_teams") + 1; 
            }

        } 

        // fill the match-plan for the round starting with the last match, then going backwards
        // Start with just 2 tables. Distribution to 4 tables is done afterwards.

        for($match = pp("r_matches_per_round"); $match >= 1; $match-- ) {

            $team_2 = $team;
            r_get_next_team($team);
            $team_1 = $team;
            r_get_next_team($team);

            r_add_match($round, $match, $team_1, $team_2); 

        } // for $match n to 1




        // With four tables move every second line to the other pair.
        if (pp("r_tables") == 4) {



            foreach ($r_match_plan as &$r_m) {

                if ( $r_m['match'] % 2 == 0) {
                    // Move table assignments from 1-2 to 3-4
                    $r_m['table_1'] = 3;
                    $r_m['table_2'] = 4;
                }           
            }
        }

    } // for $rounds 0 to 3 

    // Ensure that matches in TR are on the same tables as in RG1  
    // This quality measure Q2

    // Sequence of matches is correct, but the table assigment must be copied from RG1 to TR

    if(pp("j_lanes") == 2 || pp("j_lanes") == 4) {

        // 2 and 4 lanes correspond to 2 teams per match
        // Easy!

        for ($match0 = 1; $match0 <= pp("r_matches_per_round"); $match0++) {
        
            // Finde das Match in Runde 0 mit Matchnummer $match0
            $m0 = collect($r_match_plan)->first(fn($m) => $m['round'] === 0 && $m['match'] === $match0);
            if (!$m0) continue;

            $team = $m0['team_1'];

            // Finde das Match in Runde 1, in dem team_1 gleich ist
            $m1 = collect($r_match_plan)->first(fn($m) => $m['round'] === 1 && $m['team_1'] === $team);
            if (!$m1) continue;

            // Passe Tische in Runde 0 an
            foreach ($r_match_plan as &$match) {
                if ($match['round'] === 0 && $match['match'] === $match0) {
                    $match['table_1'] = $m1['table_1'];
                    $match['table_2'] = $m1['table_2'];
                    break;
                }
            }
            unset($match); // Referenz freigeben
        }

    }


    // Build TR from RG1

    /* TODO 

    // Calculate the shift needed backwards from RG1 to TR
    // 
    // Four judging rounds: shift once               
    //   2 lanes: two teams -> one match
    //   3 lanes: three teams -> two match
    //   4 lanes: four teams -> two matches
    //   5 lanes: fives teams -> three matches
    //
    // Five or six judging rounds: shift twice, because there is no robot game linked to judging round two
    //   2 lanes: two teams -> two matches
    //   3 lanes: three teams -> three matches
    //   4 lanes: four teams -> four matches
    //   ...

    if (pp("j_rounds") == 4) {
        $r_shift = ceil(pp("j_lanes") / 2);                  // TODO old code treated one lane differently
    } else {
        $r_shift = pp("j_lanes");
    }
    
    // Prepare to adjust asymmetric RGs
    $r_empty_match = 0;

    // Iterate through each match
    for ($match = 1; $match <= pp("r_matches_per_round"); $match++) {

         TODO


        // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
        //
        // The issue only happens if pp("r_asym") is true
        // This means pp("c_teams") = 10, 14, 18, 22 or 26 teams (or one team less)
        //
        // pp("c_teams")  pp("j_rounds")  pp("j_lanes")   Match to add the empty game
        //   10         5        2            3
        //   14         5        3            4
        //   18         6        3            4
        //   18         5        4            5
        //   22         6        4            5
        //   25         5        5            6
        //
        // --> match = pp("j_lanes") + 1
        //
        // Solution is to add an empty match
        // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan

        if (pp("r_asym") && $match == pp("j_lanes") + 1) {
            // Add a break = do nothing and move to the next match
            // Set $r_empty_match to 1 to move all other matches down
            $r_empty_match = 1;
            r_add_match(0, $match, 0, 0 );
            
        }
            TODO 

        if ($match - $r_shift > 0) {
            // Shift matches down from the top of RG1
            r_add_test_match($match + $r_empty_match, $match - $r_shift);
            
        } else {
            // Cycle matches up from the bottom of RG1
            r_add_test_match($match + $r_empty_match, $match - $r_shift + pp("r_matches_per_round"));
        }

    } // for matches 1 to n


    */

    
}

// Add activity for one match to the database considering robot check and number of tables
function r_insert_one_match($r_time, $duration, $table_1, $team_1, $table_2, $team_2, $robot_check) {

    // Approach: If robot check is needed, add it first and then the match. Otherwise, add the match directly.
    // The time provide to the function is the start time of the match, regardless of robot check.

    // $time is local to this function. $r_time needs to be adjusted by the caller of this function.
    $time = clone $r_time;

    // With robot check, that comes first and the match is delayed accordingly   
    if ($robot_check) {

        db_insert_activity(ID_ATD_R_CHECK, $time, pp('r_duration_robot_check'), 0, 0, $table_1, $team_1, $table_2, $team_2);
        g_add_minutes($time, pp('r_duration_robot_check'));

    }

    db_insert_activity(ID_ATD_R_MATCH, $time, $duration,  0, 0, $table_1, $team_1, $table_2, $team_2);
    g_add_minutes($time, $duration);

    return $time;

}

// Easy access to the matches in the plan
function r_insert_one_round($round) {

    global $r_time;
    global $r_match_plan; 
    
    switch($round) {
        case 0:
            db_insert_activity_group(ID_ATD_R_ROUND_TEST);
            break;
        case 1:
            db_insert_activity_group(ID_ATD_R_ROUND_1);
            break;
        case 2:
            db_insert_activity_group(ID_ATD_R_ROUND_2);
            break;
        case 3:
            db_insert_activity_group(ID_ATD_R_ROUND_3);            
    }

    // Filter the match plan for the given round
    $filtered_matches = array_filter($r_match_plan, function($match) use ($round) {
        return $match['round'] == $round;
    });

    // Sort the filtered matches by match number in increasing order
    usort($filtered_matches, function($a, $b) {
        return $a['match'] - $b['match'];
    });

    foreach ($filtered_matches as $match) {
    
        
        if ($round == 0) {

            // Test round
            $duration = pp("r_duration_test_match");

        } else {

            // RG1 to RG3
            $duration = pp("r_duration_match");
        }

        // In exotic cases the test round may contain an empty match. Skip generating the activity.
        if (!($match['team_1'] == 0 && $match['team_2'] == 0)) {

            // add activities for one match. This includes robot check, if selected by organizer
            r_insert_one_match($r_time, $duration, $match['table_1'], $match['team_1'], $match['table_2'], $match['team_2'], pp("r_robot_check"));
        }

        if (pp("r_tables") == 2) {              
            
            //Next match has to wait until this match is over
            g_add_minutes($r_time, $duration);

        } 
        else {              

            if($round == 0) {
            // In test round with four tables, match starts alternate between 5 and 10 minutes
            
                if($match['match'] % 2 == 1) {

                    g_add_minutes($r_time, pp("r_duration_next_start"));

                } else {

                    g_add_minutes($r_time, $duration - pp("r_duration_next_start"));
                }

            } else {

                // Next match starts 5 min later, while this match is still running.
                g_add_minutes($r_time, pp("r_duration_next_start"));
            }    

        }


    } // for each match in round

    // Four tables only: 
    // When the last match is over, r_time is correct for another match, but not for the total duartion.
    // We fix that.
    if (pp("r_tables") == 4 ) {
        g_add_minutes($r_time, $duration - pp("r_duration_next_start") );
    }

    // With robot check the rounds start with the checkers, not with teh referees.
    // Thus we can take the duration out for the start of the next action.
    if (pp("r_robot_check")) {
        g_add_minutes($r_time, -1 * pp("r_duration_robot_check"));
    }

    // Create inserted block or break before NEXT round.
    switch ($round) {
        case 0:
            g_insert_point(ID_IP_RG_1);
            break;
        case 1:
            if ( pp("e_mode") == ID_E_MORNING || pp("e_mode") == ID_E_AFTERNOON ) {
                // Explore integration using Challenge lunch break
                e_integrated();
            } else {
                // independent lunch
                g_insert_point(ID_IP_RG_2);
            }
            break;
        case 2:
            g_insert_point(ID_IP_RG_3);
    }

}

function c_presentations () {

    global $c_time;

    // Duration:
    // 5 minutes for each presentation
    // Buffer before and after to get organized in the room is managed outside of this function
    // Additional buffer, because team will likely overrun the 5 minutes.

    db_insert_activity_group(ID_ATD_C_PRESENTATIONS);

    /* 2024 version: x-time 5 Minutes

    for ($p = 1; $p <= pp("c_presentations"); $p++) {
        db_insert_activity(ID_ATD_C_PRESENTATIONS, $c_time, pp("c_duration_presentation") );
        g_add_minutes($c_time, pp("c_duration_presentation"));
    }       

    */

    $duration = pp("c_presentations") * pp("c_duration_presentation") + 5; // 5 minutes buffer for overruns

    db_insert_activity(ID_ATD_C_PRESENTATIONS, $c_time, $duration );
    g_add_minutes($c_time, $duration);

} 

function c_briefings($t, $c_day) {           

    global $r_time;
    global $j_time;

    g_debug_log(1, "Challenge briefings");

    // $t is start of opening. Backwards calcuations are done relative to this time.

    // FLL Challenge coaches
    // Briefing is before opening on first day only. No choice for organizer.

    if ($c_day == 1) {

        db_insert_activity_group(ID_ATD_C_COACH_BRIEFING);
        db_insert_activity(ID_ATD_C_COACH_BRIEFING, g_shift_minutes($t, -1 * (pp("c_duration_briefing") + pp("c_ready_opening"))), pp("c_duration_briefing"));    

    } 

    // FLL Challenge Judges
    // Briefing is on main day. Organizer can choose if before or after opening.

    db_insert_activity_group(ID_ATD_C_JUDGE_BRIEFING);
    
    if (! pp("j_briefing_after_opening")) {

        db_insert_activity(ID_ATD_C_JUDGE_BRIEFING, g_shift_minutes($t, -1 * (pp("j_duration_briefing") + pp("c_ready_opening"))), pp("j_duration_briefing"));    

    } else {

        g_add_minutes($j_time, pp("j_ready_briefing"));
        db_insert_activity(ID_ATD_C_JUDGE_BRIEFING, $j_time, pp("j_duration_briefing")); 
        
        // move time forward
        g_add_minutes($j_time, pp("j_duration_briefing"));   

    }
    

    // FLL Challenge Referees
    // Briefing is on boths day, if applicable. Timing and durations can be set by the organizer.

    db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);

    if (! pp("r_briefing_after_opening")) {

        if ($c_day == 1) {
            // One day event: Full briefing
            db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, g_shift_minutes($t, -1 * (pp("r_duration_briefing") + pp("c_ready_opening"))), pp("r_duration_briefing"));    
        } else {
            // Second day of the event: Short briefing
            db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, g_shift_minutes($t, -1 * (pp("r_duration_briefing_2") + pp("c_ready_opening"))), pp("r_duration_briefing_2"));    
        }

    } else {

        g_add_minutes($r_time, pp("r_ready_briefing"));

        if ($c_day == 1) {
            // One day event: Full briefing
            db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, $r_time, pp("r_duration_briefing"));  
            g_add_minutes($r_time, pp("r_duration_briefing"));  
        } else {
            // Second day of the event: Short briefing
            db_insert_activity(ID_ATD_R_REFEREE_BRIEFING, $r_time, pp("r_duration_briefing_2"));
            g_add_minutes($r_time, pp("r_duration_briefing_2"));
        }

    }

    // Buffer between opening (or briefing respectively) and first action for teams and judges
    g_add_minutes($j_time, pp("j_ready_action"));
    g_add_minutes($r_time, pp("r_ready_action"));

}    


function r_final_round($team_count) {

    global $r_time;

    g_debug_log(1, "Robot Game final round with " . $team_count . " teams");

    switch($team_count) {

        case 16:
            db_insert_activity_group(ID_ATD_R_FINAL_16);

            // 4 tables = alternating between tables
                
            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));

            r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));
            
            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));

            r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));

            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));

            r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));
            
            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_next_start"));

            r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_16"));
            g_add_minutes($r_time, pp("r_duration_match"));

            break;

        case 8:    
            db_insert_activity_group(ID_ATD_R_FINAL_8);

            if (pp("r_tables") == 2) {

                // 2 tables = matches in sequence
                
                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_match"));
            
                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_match"));

                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_match"));

                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_match"));
                
            } else {
                
                // 4 tables = alternating between tables
                
                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_next_start"));

                r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_next_start"));
                
                r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_next_start"));

                r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_8"));
                g_add_minutes($r_time, pp("r_duration_match"));

            }

            break;

        case 4:
            db_insert_activity_group(ID_ATD_R_FINAL_4);

            // Texts differ depening on if there was a QF, but it will take place in both case

            if (pp("r_quarter_final")) {
                
                // TODO texts: QF1, QF2, QF3, QF4

                if (pp("r_tables") == 2) {

                    // 2 tables = matches in sequence
                        
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));    
                    g_add_minutes($r_time, pp("r_duration_match"));
                
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));    
                    g_add_minutes($r_time, pp("r_duration_match"));

                    
                } else {
                    
                    // 4 tables = alternating between tables
                    
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));
                    g_add_minutes($r_time, pp("r_duration_next_start"));

                    r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_4"));    
                    g_add_minutes($r_time, pp("r_duration_match"));

                }

            } else {

                // TODO texts: RG1, RG2, RG3, RG4

                if (pp("r_tables") == 2) {

                    // 2 tables = matches in sequence
                    
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));
                    g_add_minutes($r_time, pp("r_duration_match"));
                
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));
                    g_add_minutes($r_time, pp("r_duration_match"));

                    
                } else {
                    
                    // 4 tables = alternating between tables
                    
                    r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_4"));
                    g_add_minutes($r_time, pp("r_duration_next_start"));

                    r_insert_one_match($r_time, pp("r_duration_match"), 3, 0, 4, 0, pp("r_robot_check_4"));
                    g_add_minutes($r_time, pp("r_duration_match"));

                }

            }

            break;

        case 2:
            db_insert_activity_group(ID_ATD_R_FINAL_2);

            // 2 matches in sequence flipping the teams
            
            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_2"));
            g_add_minutes($r_time, pp("r_duration_match"));

            // If robot check is on, the match was delayed by the first check.
            // Need to add that time before going on
            if (pp("r_robot_check")) {
                g_add_minutes($r_time, pp("r_duration_robot_check"));
            }

            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, false); // only match without robot check
            g_add_minutes($r_time, pp("r_duration_match"));

    }     

    if ($team_count <> 2) {

        // If robot check is on, the first match was delayed by the first check.
        // Need to add that time before going on
        if (pp("r_robot_check")) {
            g_add_minutes($r_time, pp("r_duration_robot_check"));
        }

        // Additional 5 minutes to show who advances and for those teams to get ready. Not needed after final.
        g_add_minutes($r_time, pp("r_duration_results"));
    }    

}

     

     
 function j_judging_one_round($c_block, $j_t) {

    global $j_time;

    db_insert_activity_group(ID_ATD_C_JUDGING_PACKAGE);

    // with team
    for ($j_l = 1; $j_l <= pp("j_lanes"); $j_l++) {
        
        // Not all lanes might be full
        if ($j_t + $j_l <= pp("c_teams")) {
            
            db_insert_activity(ID_ATD_C_WITH_TEAM, $j_time, pp("j_duration_with_team"), $j_l, $j_t + $j_l, 0, 0, 0, 0);
            
        }
    }
    g_add_minutes($j_time, pp("j_duration_with_team"));

    // scoring without team
    for ($j_l = 1; $j_l <= pp("j_lanes"); $j_l++) {
        
        // Not all lanes might be full
        if ($j_t + $j_l <= pp("c_teams")) {

            db_insert_activity(ID_ATD_C_SCORING, $j_time, pp("j_duration_scoring"), $j_l, $j_t + $j_l, 0, 0, 0, 0);
        }
    }
    g_add_minutes($j_time, pp("j_duration_scoring"));

    // breaks before NEXT round
    if ( (pp("j_rounds") == 4 && $c_block == 2) ||
         (pp("j_rounds") > 4 && $c_block == 3) ) {
        // lunch break
        g_add_minutes($j_time, pp("j_duration_lunch"));
    } else {
        // normal break, but not after final block
        if ($c_block < pp("j_rounds")) {
            g_add_minutes($j_time, pp("j_duration_break"));
        }
    }

}


        






?>
