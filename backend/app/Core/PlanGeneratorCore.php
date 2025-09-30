<?php

namespace App\Core;

use App\Core\MatchPlan;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;

class PlanGeneratorCore
{
    private int $planId;
    private ActivityWriter $writer;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
        $this->writer = new ActivityWriter($planId);
    }

    public function generate(int $planId): void
    {
        // Parameter laden
        PlanParameter::load($planId);

        // Start-Log
        Log::info("PlanGeneratorCore: Start generation for plan {$planId}");

        $matchPlan = new MatchPlan($this->writer);
        $matchPlan->create();





        
        // End-Log
        Log::info("PlanGeneratorCore: Finished generation for plan {$planId}");
    }



    
    // ***********************************************************************************
    // Challenge functions
    // ***********************************************************************************


    // ***********************************************************************************
    // Robot Game match plan
    // ***********************************************************************************





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





}