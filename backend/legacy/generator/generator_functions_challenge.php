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

            $r_match_plan[] = [
                'round' => $round,
                'match' => $match,
                'table_1' => 1,  
                'table_2' => 2,  
                'team_1' => ($team_1 > pp("c_teams")) ? 0 : $team_1,      // Change volunteer from pp("c_teams")+1 to 0
                'team_2' => ($team_2 > pp("c_teams")) ? 0 : $team_2,      // Change volunteer from pp("c_teams")+1 to 0
            ];

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

    // Now, ensure that matches in TR are on the same tables as in RG1  
    // This is quality measure Q2

    // Sequence of matches in TR is already correct, but the table assigment must be copied from RG1 to TR

    if ( (pp("j_lanes") % 2 === 1) && pp("r_tables") == 4  && pp("j_rounds") == 4 )  {
        
        // Special case where lanes are (1,3,5), 4 tables and 4 judging rounds
        // Q2 not met, but match plan for TR works! 
        // Hits 8 configuations as of Sep 3, 2025
        // TODO

    } else {

        for ($match0 = 1; $match0 <= pp("r_matches_per_round"); $match0++) {

            foreach ($r_match_plan as &$match) {
                if ($match['round'] === 0 && $match['match'] === $match0) {
                    $team1 = $match['team_1'];
                    $team2 = $match['team_2'];

                    // Search for Team 1 in Round 1
                    $m1 = collect($r_match_plan)->first(function ($m) use ($team1) {
                        return $m['round'] === 1 && ($m['team_1'] === $team1 || $m['team_2'] === $team1);
                    });
                    if ($m1) {
                        $match['table_1'] = ($m1['team_1'] === $team1) ? $m1['table_1'] : $m1['table_2'];
                    }

                    // Search for Team 2 in Round 1
                    $m2 = collect($r_match_plan)->first(function ($m) use ($team2) {
                        return $m['round'] === 1 && ($m['team_1'] === $team2 || $m['team_2'] === $team2);
                    });
                    if ($m2) {
                        $match['table_2'] = ($m2['team_1'] === $team2) ? $m2['table_1'] : $m2['table_2'];
                    }

                    break; // 
                }
            }
        }
        unset($match);

        if ( pp('r_asym') ) {
            
            // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
            //
            // The issue only happens if r_asym is true
            // This means c_teams = 10, 14, 18, 22 or 26 teams (or one team less)

            // Solution is to add an empty match at tables 3+4 after j_lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan

            // Neue Liste aufbauen
            $newList = [];

            foreach ($r_match_plan as $entry) {

                // Change only TR only 
                if ($entry['round'] === 0) {
                 
                    if ($entry['match'] > pp("j_lanes")) {
                        // Shift match number for all afterwards
                        $entry['match'] += 1;
                    }
                } 

                // copy all modified or unmodified entries
                $newList[] = $entry;
                    
            }

            // Insert new match after j_lanes matches
            $newList[] = [
                'round' => 0,
                'match' => pp("j_lanes") + 1,
                'table_1' => 3,
                'table_2' => 4,
                'team_1' => 0,
                'team_2' => 0,
            ];
            $r_match_plan = $newList;

        } 
    }   
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

    // With robot check, the next start is for robot-check, not the match itself.
    // Thus we need to add the match duration here.

    if (pp("r_robot_check")) {
        g_add_minutes($r_time, pp("r_duration_robot_check"));
    }

    // Four tables only: 
    // When the last match is over, r_time is correct for another match, but not for the total duration.
    // We fix that.
    if (pp("r_tables") == 4 ) {

        g_add_minutes($r_time, pp("r_duration_match") - pp("r_duration_next_start")  );
        
    }

    // Create inserted block or break before NEXT round.
    // This needs the current r_time 
    switch ($round) {
        case 0:
            g_insert_point(ID_IP_RG_TR, pp("r_duration_break"));
            break;
        case 1:
            if ( pp("e_mode") == ID_E_MORNING || pp("e_mode") == ID_E_AFTERNOON ) {
                // Explore integration using Challenge lunch break
                e_integrated();
            } else {
                // independent lunch

                // If a hard break is set, don't do anything here
                if (pp('c_duration_lunch_break') == 0) {
                    g_insert_point(ID_IP_RG_1, pp("r_duration_lunch"));
                }
            }
            break;
        case 2:
            g_insert_point(ID_IP_RG_2, pp("r_duration_break"));
            break;
        case 3:
            g_insert_point(ID_IP_RG_3, pp("r_duration_results"));
    }

}

function c_presentations () {

    global $r_time;

    // Duration:
    // 5 minutes for each presentation
    // Buffer before and after to get organized in the room is managed outside of this function
    // Additional buffer, because team will likely overrun the 5 minutes.

    db_insert_activity_group(ID_ATD_C_PRESENTATIONS);

    $duration = pp("c_presentations") * pp("c_duration_presentation") + 5; // 5 minutes buffer for overruns

    db_insert_activity(ID_ATD_C_PRESENTATIONS, $r_time, $duration );
    g_add_minutes($r_time, $duration);

    // Create inserted block or planned delay.

    if( !pp("c_presentations_last") )
        g_insert_point(ID_IP_PRESENTATIONS, pp("c_ready_presentations")); // back to robot game
    else {
        g_insert_point(ID_IP_PRESENTATIONS, pp("c_ready_awards")); // to awards
    }

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

            // If robot check is on, the match was delayed by the first check.
            // Need to add that time before going on
            if (pp("r_robot_check_16")) {
                g_add_minutes($r_time, pp("r_duration_robot_check"));
            }

            // Additional 5 minutes to show who advances and for those teams to get ready. 
            g_add_minutes($r_time, pp("r_duration_results"));

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

            // If robot check is on, the match was delayed by the first check.
            // Need to add that time before going on
            if (pp("r_robot_check_8")) {
                g_add_minutes($r_time, pp("r_duration_robot_check"));
            }

            // Additional 5 minutes to show who advances and for those teams to get ready.
            g_add_minutes($r_time, pp("r_duration_results"));

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
            
            // If robot check is on, the match was delayed by the first check.
            // Need to add that time before going on
            if (pp("r_robot_check_4")) {
                g_add_minutes($r_time, pp("r_duration_robot_check"));
            }
            
            // Create inserted block or planned delay. 
            g_insert_point(ID_IP_RG_SEMI_FINAL, pp("r_duration_results"));

            break;

        case 2:
            db_insert_activity_group(ID_ATD_R_FINAL_2);

            // 2 matches in sequence flipping the teams
            
            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, pp("r_robot_check_2"));
            g_add_minutes($r_time, pp("r_duration_match"));

            // If robot check is on, the match was delayed by the first check.
            // Need to add that time before going on
            if (pp("r_robot_check_2")) {
                g_add_minutes($r_time, pp("r_duration_robot_check"));
            }

            r_insert_one_match($r_time, pp("r_duration_match"), 1, 0, 2, 0, false); // only match without robot check
            g_add_minutes($r_time, pp("r_duration_match"));

            // Create inserted block or planned delay.
            g_insert_point(ID_IP_RG_FINAL, pp("c_ready_awards"));

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

        // If a hard break is set, don't do anything here
        if (pp('c_duration_lunch_break') == 0) {
            g_add_minutes($j_time, pp("j_duration_lunch"));
        }
    } else {
        // normal break, but not after final block
        if ($c_block < pp("j_rounds")) {
            g_add_minutes($j_time, pp("j_duration_break"));
        }
    }

}


        






?>
