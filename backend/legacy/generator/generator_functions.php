<?php

// ***********************************************************************************
// Useful functions
// ***********************************************************************************

// Handling time objects

// Function to add minutes to the time
function g_add_minutes(DateTime $time, $minutes)
{
    $intervalSpec = 'PT' . abs((int)$minutes) . 'M';
    $interval = new DateInterval($intervalSpec);

    if ($minutes < 0) {
        $interval->invert = 1;
    }

    $time->add($interval);
}

// Calculate difference between two times
function g_diff_in_minutes(DateTime $time1, DateTime $time2)
{
    // Calculate the difference between the two DateTime objects
    $interval = $time1->diff($time2);

    // Convert the difference to total minutes
    $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    // Check if time1 is earlier than time2
    if ($time1 < $time2) {
        // Return negative minutes if time1 is earlier than time2
        return -$minutes;
    }

    // Return the total minutes (positive)
    return $minutes;
}

// Debug output
function g_debug_timing($text)
{

    global $DEBUG;
    global $c_block;
    global $c_time;
    global $j_time;
    global $j_next;
    global $r_time;
    global $r_next;
    global $r_start_shift;

    if ($DEBUG >= 3) {
        echo "<b>$text</b> round:$c_block ct:{$c_time->format('H:i')} jt:{$j_time->format('H:i')} jn:{$j_next->format('H:i')} rt:{$r_time->format('H:i')} rn:{$r_next->format('H:i')} rss:$r_start_shift <br>";
    }
}


// ***********************************************************************************
// Robot Game match plan
// ***********************************************************************************


function r_get_next_team(&$team, $c_teams, $r_need_volunteer)
{

    // Get the next team with lower number
    // When 0 is reached cycle to max number
    // Include volunteer team if needed

    $team--;

    if ($team == 0) {
        if ($r_need_volunteer) {
            $team = $c_teams + 1; // Volunteer team
        } else {
            $team = $c_teams;
        }
    }
}

function r_add_match(&$r_match_plan, $c_teams, $round, $match, $team_1, $team_2, $table_1 = 1, $table_2 = 2)
{

    // Add a match to the match plan array
    // Table default to 1 and 2 as this the most common use case

    $r_match_plan[] = [
        'round' => $round,
        'match' => $match,
        'table_1' => $table_1,  // Default to 1 if not provided
        'table_2' => $table_2,  // Default to 2 if not provided
        'team_1' => ($team_1 > $c_teams) ? 0 : $team_1,      // Change volunteer from $c_teams+1 to 0
        'team_2' => ($team_2 > $c_teams) ? 0 : $team_2,      // Change volunteer from $c_teams+1 to 0
    ];
}

function r_add_test_match(&$r_match_plan, $c_teams, $m0, $m1)
{

    // Build matches in TR from RG1

    // Loop through the match plan to find the specific match in round 1 with match number $m1
    foreach ($r_match_plan as $item) {
        if ($item['round'] == 1 && $item['match'] == $m1) {

            // Create a new entry by copying values from the found match
            $new_match = [
                'round' => 0,                     // Set the new round to 0
                'match' => $m0,                   // Set the match number to $m0
                'table_1' => $item['table_1'],    // Copy table_1 from the existing match
                'table_2' => $item['table_2'],    // Copy table_2 from the existing match
                'team_1' => ($item['team_1'] > $c_teams) ? 0 : $item['team_1'],      // Copy team_1 from the existing match. Volunteer not needed.
                'team_2' => ($item['team_2'] > $c_teams) ? 0 : $item['team_2'],      // Copy team_2 from the existing match. Volunteer not needed.
            ];

            // Add the new match to the match plan array
            $r_match_plan[] = $new_match;

            // As only one match is added, break the loop
            break;
        }
    }
}

function r_match_plan($c_teams, $r_tables, $j_lanes, $j_rounds,
                      &$r_match_plan, &$r_matches_per_round, &$r_need_volunteer, &$r_asym, $g_finale)
{

    // Create the robot game match plan regardless of the number of tables and timing

    global $DEBUG;

    $r_matches_per_round = ceil($c_teams / 2);                                     // need one match per two teams
    $r_need_volunteer = $r_matches_per_round != $c_teams / 2;                      // uneven number of teams --> "need a volunteer without scoring"
    $r_asym = $r_tables == 4 && (($c_teams % 4 == 1) || ($c_teams % 4 == 2));      // 4 tables, but not multiple of 4 --> table 3/4 ends before 1/2

    if ($DEBUG >= 1) {
        echo "<h2>Robot Game Match-Plan $c_teams-$j_lanes-$r_tables</h2>";
        echo "C teams: $c_teams<br>";
        echo "J lanes: $j_lanes<br>";
        echo "R tables: $r_tables<br>";
        echo "J rounds: $j_rounds<br>";
        echo "RG matches per round: $r_matches_per_round<br>";
        echo "RG need volunteer: " . ($r_need_volunteer ? 'Yes' : 'No') . "<br>";
        echo "RG asymmetric: " . ($r_asym ? 'Yes' : 'No') . "<br>";
        echo "Finale: " . ($g_finale ? 'Yes' : 'No') . "<br>";
    }

    // Generate rounds 1 to 3 matching the judging round
    // Then build the test round from round 1
    // - preserve the table assignments
    // - shift matches "backwards" to fit judging round 1

    for ($round = 1; $round <= 3; $round++) {

        // Fill the lines from bottom to top
        // Start with adding teams that are scheduled for judging first. They will be last in the RG round.
        // Add all other teams in decreasing order. Flip from 0 to highest team-number.
        // If the number of teams is not even, add "?" first.

        // For four judging rounds, robot game teams are rotated by one per block
        // However, for five and six judging rounds, block 2 is skipped. Thus rotation is +1

        switch ($j_rounds) {
            case 4:
                $team = $round + 1;
                break;

            case 5:
                if (!$g_finale) {
                    $team = $round + 2; // No robot game in block 2.
                } else {
                    // Special case for the finale: TR is on a different day
                    // Thus we have to match 5 rounds of judging to 3, not 4 rounds of RG
                    switch ($round) {
                        case 1:
                            $team = 1;
                            break;
                        case 2:
                            $team = 3;
                            break;
                        case 3:
                            $team = 4;
                            break;
                    }
                }
                break;

            case 6:
                $team = $round + 2; // No robot game in block 2.
                break;
        }

        // Rotation depends on the number of judging lanes
        $team = $team * $j_lanes;

        // Last round of judging might not be full in round 3
        if ($team > $c_teams) {
            $team = $c_teams;
            if ($r_need_volunteer) {
                $team++;
            }
        }

        // For 4 judging lanes there is optimized code.
        // For less lanes we use a robust yet not optimal code.
        // Future versions may include more optimizations. For now, dynamic creation is more important.

        switch ($j_lanes) {
            case 1:
            case 2:
                // With only on or two judging lanes, there are two option to get teams to different tables
                // - switch sides
                // - move to a different table pair (of course only if 4 tables are used)

                // Fill the match plan bottom to top
                for ($match = $r_matches_per_round; $match >= 1; $match--) {
                    // Team number decreases while building the plan
                    switch ($round) {
                        case 1:
                            // 1-2
                            $c2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);
                            $c1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);
                            break;
                        case 2:
                        case 3:
                            // 2-1
                            $c1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);
                            $c2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);
                            break;
                    }

                    // Add the match to the plan
                    r_add_match($r_match_plan, $c_teams, $round, $match, $c1, $c2);
                }
                break; // 1 or 2 lanes

            case 3:
                // With three judging lanes, we look at a group of six teams to rotate

                // Fill the match plan bottom to top looking at TWO matches for optimization
                for ($match = $r_matches_per_round; $match >= 1; $match -= 3) {
                    // If team number is not a multiple of 6, extra attention is needed on the first two matches.
                    if ($match == 1) {
                        // Allocate remaining 2 teams in one match
                        switch ($round) {

                            case 1:
                            case 2:
                            case 3:
                                // 1-2
                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $c1 = $team;

                                r_add_match($r_match_plan, $c_teams, $round, 1, $c1, $c2);

                                break;
                        }
                    } elseif ($match == 2) {
                        // Allocate remaining 4 teams in two matches
                        switch ($round) {

                            case 1:
                            case 2:
                            case 3:
                                // 1-2
                                // 3-4

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $p1 = $team;

                                r_add_match($r_match_plan, $c_teams, $round, 1, $p1, $p2);
                                r_add_match($r_match_plan, $c_teams, $round, 2, $c1, $c2);

                                break;
                        }
                    } else {
                        // Full group of six teams to work with (might include "?")
                        switch ($round) {

                            case 1:
                                // 1-2
                                // 3-1
                                // 2-3

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                break;

                            case 2:
                                // 3-2
                                // 1-1
                                // 2-3

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                break;

                            case 3:
                                // 2-1
                                // 3-3
                                // 2-1

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $e2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                break;

                        } // switch round

                        r_add_match($r_match_plan, $c_teams, $round, $match - 2, $e1, $e2);
                        r_add_match($r_match_plan, $c_teams, $round, $match - 1, $p1, $p2);
                        r_add_match($r_match_plan, $c_teams, $round, $match - 0, $c1, $c2);

                        if ($match == 2) $match = 0;

                    } // if match

                } // for match

                break; // 3 lanes

            case 4:

                // The following is an optimized version for 4 judging lanes. This implies 4 tables
                // This allows to freely rotate a group of four teams, because they are at judging at the same time
                // This works easily if the number of teams is a multiple of 4 or one team less: 16, 19, 20, 23, 24

                // Fill the match plan bottom to top looking at TWO matches for the optimization
                for ($match = $r_matches_per_round; $match >= 1; $match -= 2) {

                    // team number decreases(!) while building the plan

                    // If the team number is not a multiple of 4, extra attention is needed on the first match.
                    if ($match == 1) {

                        // number of teams not a multiple of 4

                        switch ($round) {

                            case 1:

                                // 1-2
                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $c1 = $team;

                                r_add_match($r_match_plan, $c_teams, $round, 1, $c1, $c2);

                                break;

                            case 2:
                            case 3:

                                // 2-1
                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);
                                $c2 = $team;

                                r_add_match($r_match_plan, $c_teams, $round, 1, $c1, $c2);

                                break;
                        }

                    } else {

                        // we have a full group of four to work with (might include "?")

                        switch ($round) {

                            case 1:

                                // 1-2
                                // 3-4

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                break;

                            case 2:

                                // 3-1
                                // 4-2

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                break;

                            case 3:

                                // 2-4
                                // 1-3

                                $p2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c2 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $p1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);

                                $c1 = $team;
                                r_get_next_team($team, $c_teams, $r_need_volunteer);


                                break;
                        } // switch round

                        r_add_match($r_match_plan, $c_teams, $round, $match - 1, $p1, $p2);
                        r_add_match($r_match_plan, $c_teams, $round, $match - 0, $c1, $c2);

                    } // if match

                } // for match

                break; // 4 lanes

            case 6:
                // As of know this only supported for exactly 30 teams. Use for the 2025 finale at Siegen.
                // With six lanes there is always a group of six teams to rotate
                // There are four tables
                // The TR is on a different day. Thus we have to match 5 rounds of judging to 3 rounds of RG
                //
                // First two round of judging are linke to RG 1  --> start RG with 7-23, end with 1-6
                // Round three us linke to RG 2 --> end with 13-18
                // Round four and five are linked to RG 3 --> Start with 25-30, end with 19-24

                // Fill the match plan bottom to top looking
                // The decrement is 3, because we have 6 teams in 3 matches

                for ($match = $r_matches_per_round; $match >= 1; $match -= 3) {

                    switch ($round) {

                        case 1:

                            // 1-2
                            // 3-4
                            // 5-6

                            $c2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $c1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $p2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $p1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            break;

                        case 2:

                            // 4-1
                            // 2-6
                            // 3-5

                            $p2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $c2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $c1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $p1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            break;

                        case 3:

                            // 2-3
                            // 1-5
                            // 6-4

                            $c1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $p2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $c2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e2 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $e1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            $p1 = $team;
                            r_get_next_team($team, $c_teams, $r_need_volunteer);

                            break;


                    } // switch round

                    r_add_match($r_match_plan, $c_teams, $round, $match - 2, $e1, $e2);
                    r_add_match($r_match_plan, $c_teams, $round, $match - 1, $p1, $p2);
                    r_add_match($r_match_plan, $c_teams, $round, $match - 0, $c1, $c2);

                } // for match

                break; // 6 lanes

        } // switch j_lanes

    } // for rounds

    // With four tables move every second line to the other pair.
    if ($r_tables == 4) {

        foreach ($r_match_plan as &$r_m) {

            if ($r_m['match'] % 2 == 0) {
                // Move table assignments from 1-2 to 3-4
                $r_m['table_1'] = 3;
                $r_m['table_2'] = 4;
            }
        }
    }

    // Build TR from RG1

    // Calculate the shift needed backwards from RG1 to TR
    //
    // Four judging rounds: shift once
    //   Two judging lanes: two teams -> one match
    //   Three lanes: three teams -> one match
    //   Three lanes: four teams -> two matches
    //
    // Five or six judging rounds: shift twice, because there is no robot game linked to judging round two
    //   Two judging lanes: two teams -> two matches
    //   Three lanes: three teams -> three matches
    //   Three lanes: four teams -> four matches

    if ($j_rounds == 4) {
        switch ($j_lanes) {
            case 1:
                $r_shift = 0;
                break;
            case 2:
            case 3:
                $r_shift = 1;
                break;
            case 4:
                $r_shift = 2;
                break;
        }
    } else {
        $r_shift = $j_lanes;
    }

    // Prepare to adjust asymmetric RGs
    $r_empty_match = 0;

    // Iterate through each match
    for ($match = 1; $match <= $r_matches_per_round; $match++) {

        // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
        //
        // The issue only happens if $r_asym is true
        // This means $c_teams = 10, 14, 18, 22 or 26 teams (or one team less)
        //
        // $c_teams  $j_rounds  $j_lanes   Match to add the empty game
        //   10         5        2            3
        //   14         5        3            4
        //   18         6        3            4
        //   18         5        4            5
        //   22         6        4            5
        //   25         5        5            6
        //
        // --> match = $j_lanes + 1
        //
        // Solution is to add an empty match
        // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan

        if ($r_asym && $match == $j_lanes + 1) {
            // Add a break = do nothing and move to the next match
            // Set $r_empty_match to 1 to move all other matches down
            $r_empty_match = 1;
            r_add_match($r_match_plan, $c_teams, 0, $match, 0, 0);

        }

        if ($match - $r_shift > 0) {
            // Shift matches down from the top of RG1
            r_add_test_match($r_match_plan, $c_teams, $match + $r_empty_match, $match - $r_shift);

        } else {
            // Cycle matches up from the bottom of RG1
            r_add_test_match($r_match_plan, $c_teams, $match + $r_empty_match, $match - $r_shift + $r_matches_per_round);
        }
    }

    // Fix for issue with 11 or 12 teams and 3 lanes ("12-3-x fix")
    // Without this correction schedule for #04 is overlapping between judging and RG TR

    if (($c_teams == 11 || $c_teams == 12) && $j_lanes == 3) {

        // Filter entries with round = 0
        $round_zero_indices = [];
        foreach ($r_match_plan as $index => $match) {
            if ($match['round'] === 0) {
                $round_zero_indices[] = $index;
            }
        }

        // Store the last match teams
        $last_index = end($round_zero_indices);
        $last_team_2 = $r_match_plan[$last_index]['team_2'];

        if ($r_tables == 2) {

            // All match on same pair of table. Rotate all lines down.

            for ($i = count($round_zero_indices) - 1; $i > 0; $i -= 1) {
                $current_index = $round_zero_indices[$i];
                $previous_index = $round_zero_indices[$i - 1];
                $r_match_plan[$current_index]['team_2'] = $r_match_plan[$previous_index]['team_2'];
            }

            // Assign the last match teams to the first match
            $first_index = $round_zero_indices[0];
            $r_match_plan[$first_index]['team_2'] = $last_team_2;

        } else {

            // Matches on two pairs of tables. Rotate only every second line.

            for ($i = count($round_zero_indices) - 1; $i > 1; $i -= 2) {
                $current_index = $round_zero_indices[$i];
                $previous_index = $round_zero_indices[$i - 2];
                $r_match_plan[$current_index]['team_2'] = $r_match_plan[$previous_index]['team_2'];
            }

            // Assign the last match teams to the second match
            $first_index = $round_zero_indices[1];
            $r_match_plan[$first_index]['team_2'] = $last_team_2;

        }

    }


    if ($DEBUG >= 1) {

        //
        // Cross check, if table assignement are good
        //

        echo "<h3>Plan quality</h3>";

        // Initialize an array to store table assignments for each team across rounds
        $team_assignments = [];
        $opponent_assignments = [];

        // Populate the team assignments and opponent assignments array
        foreach ($r_match_plan as &$r_m) {
            $team_assignments[$r_m['team_1']][$r_m['round']] = $r_m['table_1'];
            $team_assignments[$r_m['team_2']][$r_m['round']] = $r_m['table_2'];

            $opponent_assignments[$r_m['team_1']][$r_m['round']] = $r_m['team_2'];
            $opponent_assignments[$r_m['team_2']][$r_m['round']] = $r_m['team_1'];
        }

        // Sort the teams by team number
        ksort($team_assignments);

        // Output the table
        echo "<table border='1' style='border-collapse: collapse;'>";

        // First row with merged columns
        echo "<tr>";
        echo "<th rowspan='2' style='background-color: #f2f2f2;'>Team</th>";
        echo "<th colspan='4' style='background-color: #f2f2f2;'>Table</th>";
        echo "<th colspan='3' style='background-color: #f2f2f2;'>Opponent</th>";
        echo "</tr>";

        // Second row with individual columns
        echo "<tr>";
        echo "<th style='background-color: #f2f2f2;'>TR</th>";
        echo "<th style='background-color: #f2f2f2;'>RG1</th>";
        echo "<th style='background-color: #f2f2f2;'>RG2</th>";
        echo "<th style='background-color: #f2f2f2;'>RG3</th>";
        echo "<th style='background-color: #f2f2f2;'>RG1</th>";
        echo "<th style='background-color: #f2f2f2;'>RG2</th>";
        echo "<th style='background-color: #f2f2f2;'>RG3</th>";
        echo "</tr>";

        foreach ($team_assignments as $team => $assignments) {
            // Check if the team number is within the desired range
            if ($team >= 1 && $team <= $c_teams) {
                echo "<tr>";

                // Team number cell
                echo "<td align=center style='font-weight: bold; background-color: #e6e6e6;'>$team</td>";

                // Check and color round 0 based on comparison with round 1
                $round0_table = $assignments[0] ?? '';
                $round1_table = $assignments[1] ?? '';
                $round0_color = ($round0_table === $round1_table) ? '#d4edda' : '#f8d7da'; // Pale green if same, pale red if different
                echo "<td align=center style='background-color: $round0_color;'>$round0_table</td>";

                // Prepare to collect tables used in rounds 1 to 3 for color-coding
                $tables_used = [];

                // Output and color cells for round 1 to 3 based on table usage
                for ($round = 1; $round <= 3; $round++) {
                    $table = $assignments[$round] ?? '';
                    if ($table) {
                        $tables_used[] = $table;
                    }
                }

                // Determine color for rounds 1 to 3 based on table usage
                $unique_tables = array_unique($tables_used);
                $color = '#f8d7da'; // Default to red

                if ($r_tables == 2 && count($unique_tables) == 2) {
                    $color = '#d4edda'; // Green if both tables are used
                } elseif ($r_tables == 4) {
                    if (count($unique_tables) == 3) {
                        $color = '#d4edda'; // Green if three different tables are used
                    } elseif (count($unique_tables) == 2) {
                        $color = '#fff3cd'; // Yellow if two different tables are used
                    }
                }

                // Apply the color to the round 1 to 3 cells
                echo "<td align=center style='background-color: $color;'>{$assignments[1]}</td>";
                echo "<td align=center style='background-color: $color;'>{$assignments[2]}</td>";
                echo "<td align=center style='background-color: $color;'>{$assignments[3]}</td>";

                // Prepare to collect opponents faced in rounds 1 to 3 for color-coding
                $opponents_faced = [];

                // Output and color cells for round 1 to 3 based on opponent assignments
                for ($round = 1; $round <= 3; $round++) {
                    $opponent = $opponent_assignments[$team][$round] ?? '';
                    if ($opponent) {
                        $opponents_faced[] = $opponent;
                    }
                }

                // Determine color for opponent columns based on the number of unique opponents faced
                $unique_opponents = array_unique($opponents_faced);
                $opponent_color = '#f8d7da'; // Default to red

                if (count($unique_opponents) == 3) {
                    $opponent_color = '#d4edda'; // Green if three different opponents are faced
                } elseif (count($unique_opponents) == 2) {
                    $opponent_color = '#fff3cd'; // Yellow if two different opponents are faced
                }

                // Apply the color to the round 1 to 3 opponent cells
                echo "<td align=center style='background-color: $opponent_color;'>{$opponent_assignments[$team][1]}</td>";
                echo "<td align=center style='background-color: $opponent_color;'>{$opponent_assignments[$team][2]}</td>";
                echo "<td align=center style='background-color: $opponent_color;'>{$opponent_assignments[$team][3]}</td>";

                echo "</tr>";
            }
        }

        echo "</table>";


        //
        // TR and round 1 to 3 in detail
        //

        for ($round = 0; $round <= 3; $round++) {
            echo "<h3>" . ($round == 0 ? "Test Round" : "Round $round") . "</h3>";
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th style='background-color: #f2f2f2;'>Match</th><th>Table 1</th><th>Table 2</th><th>Table 3</th><th>Table 4</th></tr>";

            // Initialize rows for each match
            $rows = [];
            foreach ($r_match_plan as &$r_m) {
                if ($r_m['round'] == $round) {
                    $row = array_fill(0, 5, ''); // Initialize an empty row with 5 columns

                    // First column for match number
                    $row[0] = $r_m['match'];

                    // Determine which tables the teams are assigned to and place them accordingly
                    if ($r_m['table_1'] == 1) {
                        // Match at tables 1 and 2
                        $row[1] = $r_m['team_1']; // Team at table 1
                        $row[2] = $r_m['team_2']; // Team at table 2
                    } else {
                        // Handle tables 3 and 4
                        $row[3] = $r_m['team_1']; // Team at table 3
                        $row[4] = $r_m['team_2']; // Team at table 4
                    }

                    $rows[] = $row;
                }
            }

            // Sort rows by match number
            usort($rows, function ($a, $b) {
                return $a[0] - $b[0];
            });

            // Output all rows
            $rowCount = 0;
            foreach ($rows as $row) {
                $rowColor = ($rowCount % 2 == 0) ? '#f9f9f9' : '#ffffff'; // Alternate row colors for striping
                echo "<tr style='background-color: $rowColor;'>";
                for ($col = 0; $col <= 4; $col++) {
                    $style = ($col == 0) ? 'font-weight: bold; background-color: #e6e6e6;' : '';
                    echo "<td align=center style='$style'>{$row[$col]}</td>";
                }
                echo "</tr>";
                $rowCount++;
            }

            echo "</table><br>";

        }
    }

}

// Add one match to the database considering robot check and number of tables
function r_insert_one_match($g_activity_group, $round, $r_time, $duration, $table_1, $team_1, $table_2, $team_2, $r_robot_check)
{
// Approach: If robot check is needed, add it first and then the match. Otherwise, add the match directly.
// The time provide to the function is the start time of the match, regardless of robot check.

    // $time is local to this function. $r_time needs to be adjusted by the caller of this function.
    $time = clone $r_time;

    // With robot check, that comes first and the match is delayed accordingly
    if ($r_robot_check) {

        db_insert_activity($g_activity_group, ID_ATD_R_CHECK, $time, g_pv('r_duration_robot_check'), 0, 0, $table_1, $team_1, $table_2, $team_2);
        g_add_minutes($time, g_pv('r_duration_robot_check'));

    }

    db_insert_activity($g_activity_group, ID_ATD_R_MATCH, $time, $duration, 0, 0, $table_1, $team_1, $table_2, $team_2);

}

// Easy access to the matches in the plan
function r_insert_one_round(&$r_match_plan, $round, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start)
{

    switch ($round) {
        case 0:
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_TEST);
            break;
        case 1:
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_1);
            break;
        case 2:
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_2);
            break;
        case 3:
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_3);
    }

    // Filter the match plan for the given round
    $filtered_matches = array_filter($r_match_plan, function ($match) use ($round) {
        return $match['round'] == $round;
    });

    // Sort the filtered matches by match number in increasing order
    usort($filtered_matches, function ($a, $b) {
        return $a['match'] - $b['match'];
    });

    foreach ($filtered_matches as $match) {


        if ($round == 0) {

            // Test round
            $duration = $r_duration_test_match;

        } else {

            // RG1 to RG3
            $duration = $r_duration_match;
        }

        // In exotic cases the test round may contain an empty match. Skip generating the activity.
        if (!($match['team_1'] == 0 && $match['team_2'] == 0)) {

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $duration, $match['table_1'], $match['team_1'], $match['table_2'], $match['team_2'], g_pv('r_robot_check'));
        }

        if ($r_tables == 2) {

            //Next match has to wait until this match is over
            g_add_minutes($r_time, $duration);

        } else {

            if ($round == 0) {
                // In test round with four tables, match starts alternate between 5 and 10 minutes

                if ($match['match'] % 2 == 1) {

                    g_add_minutes($r_time, $r_duration_next_start);

                } else {

                    g_add_minutes($r_time, $duration - $r_duration_next_start);
                }


            } else {

                // Next match starts 5 min later, while this match is still running.
                g_add_minutes($r_time, $r_duration_next_start);
            }

        }


    } // for each match in round

}

// FLL Explore judging plan
function e_judging($e_teams, $e_lanes, $e_rounds, $e_duration_with_team, $e_duration_scoring, $e_duration_break, DateTime &$e_time)
{

    // Build the plan

    // There is only one Activity Group for the full judging
    $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGING_PACKAGE);

    // Let's build the rounds
    for ($e_r = 1; $e_r <= $e_rounds; $e_r++) {

        // Judges with team

        for ($e_l = 1; $e_l <= $e_lanes; $e_l++) {
            $e_t = ($e_l - 1) * $e_rounds + $e_r;

            // Not all lanes may be full
            if ($e_t <= $e_teams) {
                db_insert_activity($g_activity_group, ID_ATD_E_WITH_TEAM, $e_time, $e_duration_with_team, $e_l, $e_t);
            }

        }
        g_add_minutes($e_time, $e_duration_with_team);

        // Judges alone do the scoring

        for ($e_l = 1; $e_l <= $e_lanes; $e_l++) {
            $e_t = ($e_l - 1) * $e_rounds + $e_r;

            // Not all lanes may be full
            if ($e_t <= $e_teams) {
                db_insert_activity($g_activity_group, ID_ATD_E_SCORING, $e_time, $e_duration_scoring, $e_l, $e_t);
            }
        }

        g_add_minutes($e_time, $e_duration_scoring);

        // Short break, but not after last team
        if ($e_r < $e_rounds) {
            g_add_minutes($e_time, $e_duration_break);
        }
    }

}


function g_generator($g_plan_id)
{

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
    // e1_ FLL Explore first group (usually morning)
    // e2_ FLL Explore second group (usually afternoon)

    // ***********************************************************************************
    // Definition of variables
    // ***********************************************************************************

    global $DEBUG;                      // Debug level. 0 = off
    global $g_plan;                     // Plan ID made global ...
    $g_plan = $g_plan_id;               // ... so that it need not be part of sub functions.

    // Global

    $g_duration_opening = g_pv('g_duration_opening');       // [expert] Duration of opening
    $g_event = 0;                                           // [from DB table] db ID of current event
    $g_event_date = new DateTime();                         // [from DB table] Date of the event !! Initalized later below !!
    $g_days = 0;                                            // [from DB table] Number of days of the event
    $g_finale = false;                                      // [from DB table] set to true if event level = 3
    $g_start_opening = g_pv('g_start_opening');             // [input time] Start of opening.

    /* TODEL
       $g_start_catering = g_pv('g_start_catering');           // [input time] Start of catering.
       $g_end_catering = g_pv('g_end_catering');               // [input time] End of catering.
       */

    $g_activity_group = 0;                                  // db ID of current g_activity_group

    // FLL Challenge
    $c_start_opening = g_pv('c_start_opening');             // [input time] Start of FLL Challenge opening stand-alone
    $c_teams = g_pv('c_teams');                             // [input integer] Number of FLL Challenge teams
    $c_duration_briefing = g_pv('c_duration_briefing');     // [expert integer] FLL Challenge coach briefing
    $c_ready_opening = g_pv('c_ready_opening');             // [expert integer] Time between briefings and opening
    $c_duration_opening = g_pv('c_duration_opening');       // [expert] Duration of FLL Challenge stand-alone opening
    $c_ready_action = g_pv('c_ready_action');               // [expert] Time between opening and first action for teams and judges
    $c_duration_transfer = g_pv('c_duration_transfer');     // [protected integer] Time for teams to transfer from RG to judging (or back)
    $c_ready_presentations = g_pv('c_ready_presentations'); // [expert integer] Time between robot game and research presentations (also used for back to robot game)
    $c_presentations = g_pv('c_presentations');             // [expert integer] Number of presentations on stage
    $c_duration_presentation = g_pv('c_duration_presentation');  // [protected integer] Duration of one presenation on stage
    $c_presentations_last = g_pv('c_presentations_last');             // [expert boolean] Research presentations at the end
    $c_ready_awards = g_pv('c_ready_awards');               // [expert integer] Time between deliberations / RG final  and awards
    $c_duration_awards = g_pv('c_duration_awards');         // [expert integer] FLL Challenge awards

    $c_block = 0;                       // Current block
    $c_time = new DateTime();           // Current time for FLL Challenge
    $c_p = 0;                           // [Temp] number of presentations on stage

    // FLL Challenge Judging
    $j_lanes = g_pv('j_lanes');                             // [input integer] Number judging teams working parallel aka lanes or "Juryspuren" in German
    $j_rounds = 0;                                          // [from DB table] Number of jury rounds in the schedule: Minimum 4 for 3x Robot Game + Test Round. Maximum 6 for fully utilized jury
    $j_duration_briefing = g_pv('j_duration_briefing');     // [expert integer] FLL Challenge judges briefing
    $j_briefing_after_opening = g_pv('j_briefing_after_opening'); // [expert boolean] Judges briefing after opening yes or no?
    $j_ready_briefing = g_pv('j_ready_briefing');           // [expert integer] Time between opening and judges briefing
    $j_duration_with_team = g_pv('j_duration_with_team');   // [protected integer] Duration of each block for judging in minutes
    $j_duration_scoring = g_pv('j_duration_scoring');       // [protected integer] Scoring per team
    $j_duration_break = g_pv('j_duration_break');           // [expert integer] Duration of breaks between teams
    $j_duration_lunch = g_pv('j_duration_lunch');           // [expert integer] Duration of lunch break
    $j_ready_deliberations = g_pv('j_ready_deliberations'); // [expert integer] Time between last team and deliberations
    $j_duration_deliberations = g_pv('j_duration_deliberations'); // [expert integer] Time for deliberations

    $j_time = new DateTime();           // Current time for judging in FLL Challenge
    $j_next = clone $j_time;            // When are judges available next?
    $j_l = 0;                           // [Temp] lane
    $j_t = 0;                           // [Temp] team

    // g_pv('')

    // FLL Challenge Robot Game
    $r_tables = g_pv('r_tables');                           // [input integer] Number of tables for Robot-Game: 2 or 4
    $r_duration_briefing = g_pv('r_duration_briefing');     // [expert integer] FLL Challenge referee briefing
    $r_briefing_after_opening = g_pv('r_briefing_after_opening'); // [expert boolean] Referee briefing after opening yes or no?
    $r_ready_briefing = g_pv('r_ready_briefing');           // [expert integer] Time between opening and referee briefing
    $r_robot_check = g_pv('r_robot_check');                 // [expert boolean] Robot Check yes or no?
    $r_duration_robot_check = g_pv('r_duration_robot_check'); // [protected integer] Duration of robot check in minutes
    $r_duration_match = g_pv('r_duration_match');           // [protected integer] Duration of each match in minutes
    $r_duration_test_match = g_pv('r_duration_test_match'); // [protected integer] Duration of each test match in minutes
    $r_duration_next_start = g_pv('r_duration_next_start'); // [protected integer] Delay between matches on 1+2 and 3+4 when four tables are used
    $r_duration_break = g_pv('r_duration_break');           // [expert integer] Duration of breaks between rounds
    $r_duration_lunch = g_pv('r_duration_lunch');           // [expert integer] Duration of lunch break
    $r_quarter_final = g_pv('r_quarter_final');             // [expert boolean] Robot game quarter final yes or no?
    $r_duration_results = g_pv('r_duration_results');       // [protected integer] Time in final rounds to announce who advances into next round
    $r_duration_debriefing = g_pv('r_duration_debriefing'); // [expert integer] Duration of debriefing at the end of each day (only used in finale)

    $r_time = new DateTime();           // Current time for judging in FLL Challenge
    $r_next = clone $r_time;            // When are referees available next?
    $r_matches_per_round = 0;           // [Calculated] Matches per round
    $r_need_volunteer = false;          // [Calculated] Robot Game needs volunteer if number teams is odd
    $r_asym = false;                    // [Calculated] Robot Game "asymetric" if 4 tables are used, but number teams mod 4 is > 1
    $r_match_plan = [];                 // [Calculated] Robot Game the match plan
    $r_duration_round = 0;              // [Calculated] Duration of each Robot Game round
    $r_duration_test_round = 0;         // [Calculated] Duration of the Robot Game test round
    $r_start_shift = 0;                 // [Calculated] Delay of start of Robot Game round to be in sync with judging
    $r_duration = 0;                    // [Calculated] Duration of one match or test match respectively. Used for $r_start_shift


    // FLL Explore

    $e_teams = g_pv('e_teams');                             // [input integer] Number of FLL Explore teams
    $e_mode = g_pv('e_mode');                               // [input integer] How FLL Explore is run in parallel to FLL Challenge

    $e_lanes = g_pv('e_lanes');                             // [input integer] Number judging teams working parallel aka lanes or "Juryspuren" in German
    $e_rounds = 0;                                          // [from DB table] Number of judging rounds. Not more than 5. Determins the number of judging lanes needed.

    $e_duration_briefing_j = g_pv('e_duration_briefing_j'); // [expert integer] FLL Explore judges briefing
    $e_briefing_after_opening_j = g_pv('e_briefing_after_opening_j'); // [expert boolean] Judges briefing after opening yes or no?
    $e_ready_briefing = g_pv('e_ready_briefing');           // [expert integer] Time between opening and judges briefing

    $e_duration_briefing_t = g_pv('e_duration_briefing_t'); // [expert integer] FLL Explore coach briefing
    $e_ready_opening = g_pv('e_ready_opening');             // [expert integer] Time between briefings and opening
    $e_start_opening = g_pv('e_start_opening');             // [expert string hh:mm] Start of opening for FLL Explore stand-alone
    $e_duration_opening = g_pv('e_duration_opening');       // [expert] Duration of FLL Explore stand-alone opening
    $e_ready_action = g_pv('e_ready_action');               // [expert] Time between opening and first action for teams and judges
    $e_duration_with_team = g_pv('e_duration_with_team');   // [protected integer] Time spend with each team
    $e_duration_scoring = g_pv('e_duration_scoring');       // [protected integer] Scoring per team
    $e_duration_break = g_pv('e_duration_break');           // [expert integer] Duration of breaks between teams
    $e_duration_lunch = g_pv('e_duration_lunch');           // [expert integer] Duration of lunch break
    $e_ready_deliberations = g_pv('e_ready_deliberations'); // [expert integer] Time between last team and deliberations
    $e_duration_deliberations = g_pv('e_duration_deliberations'); // [Calculated minutes] Time for deliberations TODO dyn?
    $e_ready_awards = g_pv('e_ready_awards');               // [expert integer] Time between deliberations and awards
    $e_duration_awards = g_pv('e_duration_awards');         // [expert integer] Time for awards TODO dyn?

    $e1_teams = 0;                      // [Calculated] Number of FLL Explore teams in "first batch"
    $e1_lanes = 0;                      // [Calculated] Number judging teams working parallel aka lanes or "Juryspuren" in German
    $e1_rounds = 0;                     // [Calculated] Number of judging rounds. Not more than 5. Determins the number of judging lanes needed.
    $e2_teams = 0;                      // [Calculated] Number of FLL Explore teams in "second batch"
    $e2_lanes = 0;                      // [Calculated] Number judging teams working parallel aka lanes or "Juryspuren" in German
    $e2_rounds = 0;                     // [Calculated] Number of judging rounds. Not more than 5. Determins the number of judging lanes needed.
    $e_time = new DateTime();           // Current time for judging in FLL Explore

    $e2_start_opening = '14:00';        // [Expert string hh:mm] Start of opening for FLL Explore afternoon stand-alone. TODO

    // Finale
    $f_start_opening_day_1 = g_pv('f_start_opening_day_1'); // [finale time] Start of small opening before LC
    $f_duration_opening_day_1 = g_pv('f_duration_opening_day_1'); // [finale integer] duration of small opening before LC
    $f_ready_action_day_1 = g_pv('f_ready_action_day_1'); // [finale integer] Time between opening and first LC / RGT
    $f_ready_briefing_day_1 = g_pv('f_ready_briefing_day_1'); // [finale integer] Time between LC delibeartion and FLL Challenge judge briefing day1
    $f_duration_briefing_day_1 = g_pv('f_duration_briefing_day_1'); // [finale integer] FLL Challenge judge briefing day1 (optional)

    $r_duration_briefing_2 = g_pv('r_duration_briefing_2'); // [finale integer] FLL Challenge referee briefing day2 (and 3)

    $f_start_opening_day_3 = g_pv('f_start_opening_day_3'); // [finale time] Start of small opening before final rounds (only if split to 3 days)
    $f_duration_opening_day_3 = g_pv('f_duration_opening_day_3'); // [finale integer] Duration of small opening before final rounds (only if split to 3 days)
    $f_ready_action_day_3 = g_pv('f_ready_action_day_3'); // [finale integer] Time between opening and RG final rounds

    // Live Challenge
    $lc_time = new DateTime();                              // Current time for Live Challenge
    $lc_duration_briefing = g_pv("lc_duration_briefing");   // [finale integer] Duration of briefing
    $lc_duration_with_team = g_pv("lc_duration_with_team"); // [protected integer] Time spend with each team
    $lc_duration_scoring = g_pv("lc_duration_scoring");     // [protected integer] Scoring per team
    $lc_duration_break = g_pv("lc_duration_break");         // [protected integer] Duration of breaks between teams
    $lc_ready_deliberations = g_pv("lc_ready_deliberations"); // [finale integer] Time between last team and deliberations
    $lc_duration_deliberations = g_pv("lc_duration_deliberations"); // [finale integer] Time for deliberations


    if ($DEBUG >= 2) {
        echo "<h2>Collect all input</h2>";
        echo "g_plan: $g_plan <br>";
    }

    // Get rounds from DB
    if ($c_teams > 0) {
        $j_rounds = db_get_from_supported_plan(ID_FP_CHALLENGE, $c_teams, $j_lanes, $r_tables);
    }

    if ($e_teams > 0) {
        $e_rounds = db_get_from_supported_plan(ID_FP_EXPLORE, $e_teams, $e_lanes);
    }

    switch ($e_mode) {

        case ID_E_MORNING:
            // Expore timing of "morning batch" depends on number teams
            // to ensure that kids are not waiting endlessly and
            // to ensure the awards ceremony can be done between RG1 and RG2,
            // there must not be more than 5 rounds by lane
            // Lanes and anything else are calculated accordingly

            // This logic is store in db table m_supported_plan and copied to table plan
            // before this script is called

            // Variables with "1" are used for better readablity of the code

            $e1_teams = $e_teams;
            $e1_lanes = $e_lanes;
            $e1_rounds = $e_rounds;
            break;

        case ID_E_AFTERNOON:
            // "afternoon batch"
            // similar to the above, Explore aligns with Challenge
            // Opening is after RG1. Awards are joint at the end of the day

            // Variables with "2" are used for better readablity of the code

            $e2_teams = $e_teams;
            $e2_lanes = $e_lanes;
            $e2_rounds = $e_rounds;
            break;

        case ID_E_INDEPENDENT:
            // Nothing here.
            // Later in the code the regular variables are used with "1" or "2".

    }


    // Get the correcsponting event from DB.
    db_get_from_plan($g_event);

    // Get the date, number of days and flag for final from DB
    db_get_from_event($g_event, $g_event_date, $g_days, $g_finale);


    // For a finale the main action is on day 2, while LC is on day 1
    if ($g_finale) {

        // Save the day for Live Challenge
        $lc_time = clone $g_event_date;

        // combine event date with start time of day 1
        list($hours, $minutes) = explode(':', $f_start_day_1);
        $lc_time->setTime((int)$hours, (int)$minutes);

        // Add one day for the main action
        $g_event_date->modify('+1 day');
    }

    // combine event date with start time of opening

    if ($c_teams > 0) {

        if ($e_mode == ID_E_MORNING) {

            // FLL Challenge and Explore combined
            list($hours, $minutes) = explode(':', $g_start_opening);

        } else {

            // FLL Challenge stand-alone
            list($hours, $minutes) = explode(':', $c_start_opening);
        }

    } else {

        // FLL Explore stand-alone
        list($hours, $minutes) = explode(':', $e_start_opening);
    }

    $g_event_date->setTime((int)$hours, (int)$minutes);

    // Copy to variables
    $c_time = clone $g_event_date;
    $r_time = clone $g_event_date;
    $e_time = clone $g_event_date;

    // ***********************************************************************************
    // Main
    // ***********************************************************************************

    // Fundamental concepts FLL Challenge
    // 1. Number of teams and judging lanes defines number and timing of judging blocks in schedule
    // 2. Robot games is aligned to that

    // Fundamental concepts FLL Explore
    // 1. Number of teams determines judging lanes
    // 2. Morning or afternoon batch. Parallel to FLL Challenge or stand-alone.
    // 3. If parallel with Challenge, your lunch for morning batch awards or afternoon batch opening respectively.

    // -----------------------------------------------------------------------------------
    // Briefings before opening
    // -----------------------------------------------------------------------------------

    if ($DEBUG >= 2) {
        echo "<h2>Briefings</h2>";
    }

    // Calculate backwards from start of opening

    if ($c_teams > 0) {

        // FLL Challenge Coaches only day 1 (either one day event or first day of multi day event)

        if ($g_days == 1) {

            $t = clone $c_time;
            g_add_minutes($t, -1 * ($c_duration_briefing + $c_ready_opening));

            $g_activity_group = db_insert_activity_group(ID_ATD_C_COACH_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_C_COACH_BRIEFING, $t, $c_duration_briefing);
        }

        // FLL Challenge Judges

        if (!$j_briefing_after_opening) {

            $t = clone $c_time;
            g_add_minutes($t, -1 * ($j_duration_briefing + $c_ready_opening));

            $g_activity_group = db_insert_activity_group(ID_ATD_C_JUDGE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_C_JUDGE_BRIEFING, $t, $j_duration_briefing);

        }

        // FLL Challenge Referees

        if (!$r_briefing_after_opening) {

            $t = clone $c_time;
            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);

            if ($g_days == 1) {
                // One day event: Full briefing
                g_add_minutes($t, -1 * ($r_duration_briefing + $c_ready_opening));
                db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $t, $r_duration_briefing);
            } else {
                // Second day of the event: Short briefing
                g_add_minutes($t, -1 * ($r_duration_briefing_2 + $c_ready_opening));
                db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $t, $r_duration_briefing_2);
            }

        }


    }

    // Explore in the morning. Either stand-alone or joint operning
    if ($e1_teams > 0) {

        // FLL Explore Coaches

        $t = clone $e_time;
        g_add_minutes($t, -1 * ($e_duration_briefing_t + $e_ready_opening));

        $g_activity_group = db_insert_activity_group(ID_ATD_E_COACH_BRIEFING);
        db_insert_activity($g_activity_group, ID_ATD_E_COACH_BRIEFING, $t, $e_duration_briefing_t);

        // FLL Explore Judges

        if (!$e_briefing_after_opening_j) {

            $t = clone $e_time;
            g_add_minutes($t, -1 * ($e_duration_briefing_j + $e_ready_opening));

            $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $t, $e_duration_briefing_j);

        }

    }


    // -----------------------------------------------------------------------------------
    // Opening
    // -----------------------------------------------------------------------------------

    if ($DEBUG >= 2) {
        echo "<h2>Opening</h2>";
    }

    if ($c_teams > 0) {

        if ($e1_teams > 0) {
            // joint opening
            $g_activity_group = db_insert_activity_group(ID_ATD_OPENING);
            db_insert_activity($g_activity_group, ID_ATD_OPENING, $c_time, $g_duration_opening);
            db_insert_activity($g_activity_group, ID_ATD_OPENING, $e_time, $g_duration_opening);
            g_add_minutes($c_time, $g_duration_opening);
            g_add_minutes($r_time, $g_duration_opening);
            g_add_minutes($e_time, $g_duration_opening);

        } else {
            // FLL Challenge only
            $g_activity_group = db_insert_activity_group(ID_ATD_C_OPENING);
            db_insert_activity($g_activity_group, ID_ATD_C_OPENING, $e_time, $c_duration_opening);
            g_add_minutes($c_time, $c_duration_opening);
            g_add_minutes($r_time, $g_duration_opening);

        }

    } else {
        if ($e1_teams > 0 || $e2_teams > 0) {
            // FLL Explore only
            $g_activity_group = db_insert_activity_group(ID_ATD_E_OPENING);
            db_insert_activity($g_activity_group, ID_ATD_E_OPENING, $e_time, $e_duration_opening);
            g_add_minutes($e_time, $e_duration_opening);

        }
    }

    // -----------------------------------------------------------------------------------
    // Briefings after opening
    // -----------------------------------------------------------------------------------

    if ($DEBUG >= 2) {
        echo "<h2>Briefings after opening</h2>";
    }

    if ($c_teams > 0) {

        // FLL Challenge Judges

        if ($j_briefing_after_opening) {

            g_add_minutes($c_time, $j_ready_briefing);

            $g_activity_group = db_insert_activity_group(ID_ATD_C_JUDGE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_C_JUDGE_BRIEFING, $c_time, $j_duration_briefing);
            g_add_minutes($c_time, $j_duration_briefing);

        }

        // FLL Challenge Referees

        if ($r_briefing_after_opening) {

            g_add_minutes($r_time, $r_ready_briefing);

            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);

            if ($g_days == 1) {
                // One day event: Full briefing
                db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $r_time, $r_duration_briefing);
                g_add_minutes($r_time, $r_duration_briefing);
            } else {
                // Second day of the event: Short briefing
                db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $r_time, $r_duration_briefing_2);
                g_add_minutes($r_time, $r_duration_briefing_2);
            }

        }

        // The longer briefing determines when the first action can happen
        if ($r_time > $c_time) {
            $c_time = clone $r_time;
        }

    }

    // Explore in the morning. Either stand-alone or joint operning
    if ($e1_teams > 0) {

        // FLL Explore Judges

        if ($e_briefing_after_opening_j) {

            g_add_minutes($e_time, $e_ready_briefing);

            $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $e_time, $e_duration_briefing_j);
            g_add_minutes($e_time, $e_duration_briefing_j);

        }

    }

    // Buffer between opening (or briefing respetively) and first action for teams and judges
    g_add_minutes($c_time, $c_ready_action);
    g_add_minutes($e_time, $e_ready_action);

    // -----------------------------------------------------------------------------------
    // FLL Explore Morning Batch
    // -----------------------------------------------------------------------------------
    // Start with FLL Explore, because awards ceremony is between FLL Challenge robot game rounds
    // Therefore, FLL Explore timing needs to be calculate first!
    // Skip all, if there are not FLL Explore teams in the morning

    if ($e1_teams > 0) {

        if ($DEBUG >= 1) {
            echo "<h2>FLL Explore - Morning Batch</h2>";
            echo "e1 Teams: $e1_teams<br>";
            echo "e1 Lanes: $e1_lanes<br>";
            echo "e1 Rounds: $e1_rounds<br>";
            echo "<br>";
        }

        e_judging($e1_teams, $e1_lanes, $e1_rounds, $e_duration_with_team, $e_duration_scoring, $e_duration_break, $e_time);

        // Buffer before all judges meet for deliberations
        g_add_minutes($e_time, $e_ready_deliberations);

        // Deliberations
        $g_activity_group = db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
        db_insert_activity($g_activity_group, ID_ATD_E_DELIBERATIONS, $e_time, $e_duration_deliberations, 0, 0);
        g_add_minutes($e_time, $e_duration_deliberations);

        // Awards for FLL Explore is next:
        // This would be the earliest time for FLL Explore awards
        // However, robot game may not have finished yet.
        // Thus the timing is determined further down

    } else {

        if ($DEBUG >= 1) {
            echo "<h2>No FLL Explore morning batch</h2>";
        }

    } // if ($e1_teams > 0)


    // -----------------------------------------------------------------------------------
    // FLL Challenge
    // -----------------------------------------------------------------------------------
    // Robot Game and Judging run parallel in sync

    if ($c_teams > 0) {

        // -----------------------------------------------------------------------------------
        // Robot Game match plan
        // -----------------------------------------------------------------------------------

        // Associative array for match plan
        $r_match_plan = [];

        // Create the robot game match plan regardless of number of tables and timing
        r_match_plan($c_teams, $r_tables, $j_lanes, $j_rounds,
            $r_match_plan, $r_matches_per_round, $r_need_volunteer, $r_asym,
            $g_finale);

        // Calculate durations per round
        // duration of one RG round in minutes
        if ($r_tables == 2) {

            // 2 teams per slot

            // Round 1 to 3: 10 minutes slot
            $r_duration_round = $r_matches_per_round * $r_duration_match;

            // Test round: 15 minutes slot
            $r_duration_test_round = $r_matches_per_round * $r_duration_test_match;

        } else {

            // 4 teams per slot.

            // Round 1 to 3: 10 minutes slot.
            $r_duration_round = ceil($c_teams / 4) * $r_duration_match;

            // Test round: 15 minutes slot.
            $r_duration_test_round = ceil($c_teams / 4) * $r_duration_test_match;

            if (!$r_asym) {

                // If symmetric, an additional 5 minutes are needed as tables three and four are used for the last match
                $r_duration_round += $r_duration_next_start;
            }

            // Same for the test round: 5 minutes need to be added in any case
            $r_duration_test_round += $r_duration_next_start;

        }

        // Robot check adds time to the rounds
        if ($r_robot_check) {
            $r_duration_round += $r_duration_robot_check;
            $r_duration_test_round += $r_duration_robot_check;
        }


        // -----------------------------------------------------------------------------------
        // FLL Challenge: Put the judging / robot game schedule together
        // -----------------------------------------------------------------------------------

        $j_time = clone $c_time;
        $r_time = clone $c_time;

        // After opening judges and referes are available without any further delays.
        // This may change per block
        $j_next = clone $j_time;
        $r_next = clone $r_time;

        g_debug_timing("Los geht's");

        // For judging team number are used in increasing order
        // $j_t is the first team in the block. The lane number is added to this.
        $j_t = 0;

        // Now create the blocks of judging with robot game aligned

        for ($c_block = 1; $c_block <= $j_rounds; $c_block++) {

            //---
            // Determine timing between judging and robot game
            //---

            // Fundamental idea
            // The teams going to judging in a given round, have the last RG matches in that round
            // Judging: 35 min         (proteced parameter)
            // Transfer time: 15 min   (proteced parameter)
            // -> The respective match must start at least 50 minutes after judging starts
            //    Additional 10 min (protected parameter) must be added if robot check is on

            // Dependence on judging lanes
            // 2 lanes -> 2 teams are gone to judging -> only the last match is impacted
            // 3 or 4 lanes -> 3 or 4 teams are gone to judgin -> the last two matches are impacted
            // 5 or 6 lanes -> 5 or 6 teams are gone to judging -> the last three matches are imapacted

            // Dependency on tables
            // 2 tables -> calculate 1 (or 2) matches backwards from end of last match
            // 4 tables -> calculate 1 match backwards
            //                        plus 5 minutes (offset between matches alternating table 1+2 and 3+4)
            //                        ! for test round plus 10 minutes

            // Determine the shift between start of judging and start of robot game 1st match
            // Positive values = robot game need to be delayed
            // Negative values = judging needs to be delayed

            // Find the end of the last match relative to start of judging
            if ($c_block == 1) {
                // RG test round
                $r_start_shift = $j_duration_with_team + $c_duration_transfer - $r_duration_test_round;
                $r_duration = $r_duration_test_match;
            } else {
                // Normal rounds RG 1 to RG 3
                $r_start_shift = $j_duration_with_team + $c_duration_transfer - $r_duration_round;
                $r_duration = $r_duration_match;
            }

            g_debug_timing("Versaut?");

            // Determine how far to go backwards from last match

            switch ($j_lanes) {

                case 1:
                case 2:
                    // Two teams -> one match
                    $r_start_shift += 1 * $r_duration;
                    break;

                case 3:
                case 4:
                    if ($r_tables == 2) {

                        // 2 tables ->  Calculate backwards 2 matches from end of last match
                        $r_start_shift += 2 * $r_duration;

                    } else {

                        // 4 tables

                        // Calculate backwards 1 full match from end of last match ...
                        $r_start_shift += 1 * $r_duration;

                        // ... For test round offset depends on which table pair is last.
                        // ... For rounds one to three it is always 5 min.
                        if ($c_block == 1 && $r_asym) {

                            $r_start_shift += $r_duration_test_match - $r_duration_next_start;

                        } else {

                            $r_start_shift += $r_duration_match - $r_duration_next_start;

                        }
                    }
                    break;

                case 5:
                case 6:

                    // 4 tables

                    // Calculate backwards 2 full matches from end of last match ...
                    $r_start_shift += 2 * $r_duration;

                    // ... For test round offset depends on which table pair is last.
                    // ... For rounds one to three it is always 5 min.
                    if ($c_block == 1 && $r_asym) {

                        $r_start_shift += $r_duration_test_match - $r_duration_next_start;

                    } else {

                        $r_start_shift += $r_duration_match - $r_duration_next_start;

                    }

                    break;
            }

            // Robot check add time before the match -> Shift robot game to later
            if ($r_robot_check) {
                $r_start_shift += $r_duration_robot_check;
            }

            // For five or six judging rounds only:
            // TR may overlap with judging block two
            // Thus, there is no need to delay judging
            if ($j_rounds > 4 && $c_block == 1 && $r_start_shift < 0) {
                $r_start_shift = 0;
            }

            // Manual optimization introduced with "fix 12-3-x" for special cases only
            // Teams might be late for judging because they have less than 15 min to move over from robot game
            // Hardcode a delay of judging by additional 5 minutes
            if ($c_block == 2 && ($c_teams == 7 || $c_teams == 8)) {
                g_add_minutes($j_next, 5);
            }

            // More manual optimization for special cases
            if ($c_block == 4 && ($c_teams == 7 || $c_teams == 8 || (($c_teams == 11 || $c_teams == 12) && $j_lanes == 3))) {
                g_add_minutes($j_next, 5);
            }

            // Extra for plans with robot check
            if ($r_robot_check) {

                switch ($r_tables) {
                    case 2:

                        switch ($c_block) {

                            case 2:
                                switch ($c_teams) {
                                    case 4:
                                    case 5:
                                    case 6:
                                        g_add_minutes($j_next, 5);
                                        break;
                                }
                                break;

                            case 4:
                                switch ($c_teams) {
                                    case 4:
                                        g_add_minutes($j_next, 5);
                                        break;
                                }
                                break;

                            case 5:
                                switch ($c_teams) {
                                    case 5:
                                    case 6:
                                        g_add_minutes($j_next, 10);
                                        break;
                                }
                                break;

                            case 6:
                                switch ($c_teams) {
                                    case 6:
                                        g_add_minutes($j_next, 10);
                                        break;
                                }
                                break;

                        }

                    case 4:

                        switch ($c_block) {

                            case 2: // 4 rounds

                                switch ($c_teams) {
                                    case 4:
                                        g_add_minutes($j_next, 5);
                                        break;

                                    case 7:
                                    case 8:
                                    case 11:
                                    case 12:
                                        g_add_minutes($j_next, 10);
                                        break;
                                }
                                break;

                            case 4: // 4 rounds

                                switch ($c_teams) {
                                    case 4:
                                    case 15:
                                    case 16:
                                        g_add_minutes($j_next, 5);
                                        break;

                                    case 7:
                                    case 8:
                                    case 11:
                                    case 12:
                                        g_add_minutes($j_next, 10);
                                        break;
                                }
                                break;

                            case 5:

                                switch ($c_teams) {
                                    case 11:   // 6 rounds
                                    case 12:   // 6 rounds
                                    case 13:   // 5 rounds
                                    case 14:   // 5 rounds
                                    case 15:   // 5 rounds
                                    case 16:   // 6 rounds
                                        g_add_minutes($j_next, 5);
                                        break;

                                    case 5:     // 5 rounds
                                    case 6:     // 6 rounds
                                    case 9:     // 5 rounds
                                    case 10:    // 5 rounds
                                        g_add_minutes($j_next, 10);
                                        break;

                                }
                                break;

                            case 6:

                                switch ($c_teams) {
                                    case 11:   // 6 rounds
                                    case 12:   // 6 rounds
                                        g_add_minutes($j_next, 5);
                                        break;

                                }
                                break;

                        }

                }

            }

            // Four teams is extra specical. One round of robot game is too short to match to judging
            if ($c_teams == 4) {
                switch ($c_block) {
                    case 2:
                        g_add_minutes($j_next, 25);
                        break;
                    case 4:
                        g_add_minutes($j_next, 10);
                        break;
                }
            }

            // Finally consider if referees are available later than judges or vice versa
            // The following decreases the shift if referees are available later than judges
            //               increases the shift if referees are available earlier
            if ($j_rounds > 4 && ($c_block == 2 || $c_block == 6)) {
                // no robot game parallel to judging. Set shift to zero for formula further to work anyway
                $r_start_shift = 0;
            } else {
                $r_start_shift -= g_diff_in_minutes($r_next, $j_next);
            }

            g_debug_timing("Vor Delay");

            // Delay robot game or judging respectively
            if ($r_start_shift > 0) {

                // Delay robot game
                $j_time = clone $j_next;
                $r_time = clone $r_next;
                g_add_minutes($r_time, $r_start_shift);

            } elseif ($r_start_shift < 0) {

                // special tweak for 23 or 24 teams in round 5
                if ($c_teams > 22 && $c_block == 5) {

                    // no need to wait for robot game
                    $j_time = clone $j_next;

                } else {

                    // Delay judging
                    $j_time = clone $j_next;
                    g_add_minutes($j_time, -$r_start_shift);

                }
                // no delay for robot game
                $r_time = clone $r_next;

            } else {

                // Meaning: r_start_shift = 0
                // No delay needed
                // both start at the same time
                $j_time = clone $j_next;
                $r_time = clone $r_next;

            }

            g_debug_timing("Nach Delay");

            //---
            // Judging
            //---

            $g_activity_group = db_insert_activity_group(ID_ATD_C_JUDGING_PACKAGE);

            // with team
            for ($j_l = 1; $j_l <= $j_lanes; $j_l++) {

                // Not all lanes might be full
                if ($j_t + $j_l <= $c_teams) {

                    db_insert_activity($g_activity_group, ID_ATD_C_WITH_TEAM, $j_time, $j_duration_with_team, $j_l, $j_t + $j_l, 0, 0, 0, 0);

                }
            }
            g_add_minutes($j_time, $j_duration_with_team);

            // scoring without team
            for ($j_l = 1; $j_l <= $j_lanes; $j_l++) {

                // Not all lanes might be full
                if ($j_t + $j_l <= $c_teams) {

                    db_insert_activity($g_activity_group, ID_ATD_C_SCORING, $j_time, $j_duration_scoring, $j_l, $j_t + $j_l, 0, 0, 0, 0);
                }
            }
            g_add_minutes($j_time, $j_duration_scoring);

            // When will judges be available next (whithout any breaks)
            $j_next = clone $j_time;

            // First team to start with in next block
            $j_t += $j_lanes;

            g_debug_timing("Nach Judging");

            // ---
            // Robot Game in parallel to judging
            // ---

            if (!$g_finale) {

                if ($c_block == 1) {

                    // Test round
                    r_insert_one_round($r_match_plan, 0, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);

                } elseif ($j_rounds == 4 || $c_block == 3 || $c_block == 4 || $c_block == 5) {

                    // RG1 to RG3

                    if ($j_rounds == 4) {

                        // If there are only four rounds of judging, TR and RG1 to RG3 are in parallel to these
                        r_insert_one_round($r_match_plan, $c_block - 1, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);

                    } else {

                        // five or six round of judging: 1 -> TR (see above), 2 skip, 3 to 5 -> RG1 to RG3
                        r_insert_one_round($r_match_plan, $c_block - 2, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);

                    }
                }

            } else {

                // Finale has TR on the day before. This is handled far down in this code parallel to live challenge.
                // For the main day, the RG rounds are aligned to the judging rounds as follows:

                switch ($c_block) {
                    case 1:
                        r_insert_one_round($r_match_plan, 1, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);
                        break;
                    case 3:
                        r_insert_one_round($r_match_plan, 2, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);
                        break;
                    case 4:
                        r_insert_one_round($r_match_plan, 3, $r_time, $r_tables, $r_duration_test_match, $r_duration_match, $r_duration_next_start);
                        break;
                }

            }

            // When will referees be available next (whithout any breaks)
            $r_next = clone $r_time;

            g_debug_timing("Nach RG");

            // ***
            // Breaks
            // ***

            // Lunch break or small break?
            // lunch break after 2 or 3 blocks respectively
            if (($j_rounds == 4 && $c_block == 2) || ($j_rounds > 4 && $c_block == 3)) {

                // ***
                // Lunch breaks
                // ***

                // FLL Explore MORNING AWARDS is timed to use FLL Challenge Robot game lunch break
                // Adjust the timing of Explore Awards and RG lunch break
                if ($e1_teams > 0) {

                    // Need to postpone awards?
                    if (g_diff_in_minutes($r_time, $e_time) > 0) {
                        $e_time = clone $r_time;
                    }

                    // Get ready e.g. judges get on stage, RG teams and refree move away ...
                    g_add_minutes($e_time, $e_ready_awards);
                    if ($r_tables == 4) {
                        g_add_minutes($e_time, $r_duration_next_start);
                    }

                    // Add FLL Explore Awards
                    $g_activity_group = db_insert_activity_group(ID_ATD_E_AWARDS);
                    db_insert_activity($g_activity_group, ID_ATD_E_AWARDS, $e_time, $e_duration_awards);
                    g_add_minutes($e_time, $e_duration_awards);

                    // Earliest to go back to Robot Game same buffer as before awards
                    g_add_minutes($e_time, $e_ready_awards);

                    // Lunch break for Explore judges
                    $g_activity_group = db_insert_activity_group(ID_ATD_E_LUNCH_JUDGE);
                    db_insert_activity($g_activity_group, ID_ATD_E_LUNCH_JUDGE, $e_time, $e_duration_lunch);
                    // do NOT increase $e_time here, because it is needed for referee's break
                    // FLL Explore morning batch is over at this time anyway

                } // FLL Explore morning awards

                // FLL Explore AFTERNOON OPENING is timed to use FLL Challenge Robot game lunch break
                // Adjust the timing of Explore Awards and RG lunch break

                if ($e2_teams > 0) {

                    //TODO this should be as LATE as possible to keep the day short for the younger kids

                    // start as early as robot games allows
                    $e_time = clone $r_time;

                    // Get ready e.g. judges get on stage, RG teams and refree move away ...
                    g_add_minutes($e_time, $e_ready_awards);          // TODO different parameter
                    if ($r_tables == 4) {
                        g_add_minutes($e_time, $r_duration_next_start);
                    }

                    // FLL Explore afternoon Opening
                    $g_activity_group = db_insert_activity_group(ID_ATD_E_OPENING);
                    db_insert_activity($g_activity_group, ID_ATD_E_OPENING, $e_time, $e_duration_opening);

                    // FLL Explore Coaches
                    $t = clone $e_time;
                    g_add_minutes($t, -1 * ($e_duration_briefing_t + $e_ready_opening));

                    $g_activity_group = db_insert_activity_group(ID_ATD_E_COACH_BRIEFING);
                    db_insert_activity($g_activity_group, ID_ATD_E_COACH_BRIEFING, $t, $e_duration_briefing_t);

                    // FLL Explore Judges

                    if (!$e_briefing_after_opening_j) {

                        // Briefing before opening

                        $t = clone $e_time;
                        g_add_minutes($t, -1 * ($e_duration_briefing_j + $e_ready_opening));

                        $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
                        db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $t, $e_duration_briefing_j);

                        g_add_minutes($e_time, $e_duration_opening);

                    } else {

                        // Briefing after opening

                        g_add_minutes($e_time, $e_duration_opening);
                        g_add_minutes($e_time, $e_ready_briefing);

                        $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
                        db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $e_time, $e_duration_briefing_j);
                        g_add_minutes($e_time, $e_duration_briefing_j);

                    }

                    // Opening and briefings are over. Get ready for action

                    g_add_minutes($e_time, $e_ready_action);

                } // FLL Explore afternoon opening

                // Lunch break for Challenge judges
                $g_activity_group = db_insert_activity_group(ID_ATD_C_LUNCH_JUDGE);

                db_insert_activity($g_activity_group, ID_ATD_C_LUNCH_JUDGE, $j_time, $j_duration_lunch);
                $j_next = clone $j_time;
                g_add_minutes($j_next, $j_duration_lunch);

                // Lunch break for Challenge referees
                $g_activity_group = db_insert_activity_group(ID_ATD_R_LUNCH_REFEREE);

                // If robot-check is on, the last match ended later
                if ($r_robot_check) {
                    g_add_minutes($r_time, $r_duration_robot_check);
                }

                if ($e1_teams > 0 && g_diff_in_minutes($e_time, $r_next) > $r_duration_lunch) {

                    // Explore action may extend the break. use their lunch break time
                    db_insert_activity($g_activity_group, ID_ATD_R_LUNCH_REFEREE, $r_time, g_diff_in_minutes($e_time, $r_next));
                    g_add_minutes($r_next, g_diff_in_minutes($e_time, $r_next));

                } else {

                    // Normal lunch break
                    db_insert_activity($g_activity_group, ID_ATD_R_LUNCH_REFEREE, $r_time, $r_duration_lunch);
                    g_add_minutes($r_next, $r_duration_lunch);
                }

                /* TODEL
                // Create start and end time for catering

                $cs = clone $g_event_date;
                list($hours, $minutes) = explode(':', $g_start_catering);
                $cs->setTime((int)$hours, (int)$minutes);

                $ce = clone $g_event_date;
                list($hours, $minutes) = explode(':', $g_end_catering);
                $ce->setTime((int)$hours, (int)$minutes);

                // Lunch break for Challenge teams
                // TODO could be one activity per team
                $g_activity_group = db_insert_activity_group(ID_ATD_C_LUNCH_TEAM);
                db_insert_activity($g_activity_group, ID_ATD_C_LUNCH_TEAM, $cs, g_diff_in_minutes($ce, $cs));

                // Lunch break for Challenge vistors
                $g_activity_group = db_insert_activity_group(ID_ATD_C_LUNCH_VISITOR);
                db_insert_activity($g_activity_group, ID_ATD_C_LUNCH_VISITOR, $cs, g_diff_in_minutes($ce, $cs));

                // Explore morning batch
                if ($e1_teams > 100000) {                       // TODO. This needs to be dynamics around the awards. Also end of judge lunch cannot be longer that end of catering

                    // Lunch break for Explore teams
                    $g_activity_group = db_insert_activity_group(ID_ATD_E_LUNCH_TEAM);
                    db_insert_activity($g_activity_group, ID_ATD_E_LUNCH_TEAM, $cs, g_diff_in_minutes($ce, $cs));

                    // Lunch break for Explore visitor
                    $g_activity_group = db_insert_activity_group(ID_ATD_E_LUNCH_VISITOR);
                    db_insert_activity($g_activity_group, ID_ATD_E_LUNCH_VISITOR, $cs, g_diff_in_minutes($ce, $cs));

                }
*/
            } else {

                // ***
                // Normal break after all other rounds, but not after the last round of the day
                // ***

                if ($c_block < $j_rounds) {

                    // Judges
                    $j_next = clone $j_time;
                    g_add_minutes($j_next, $j_duration_break);

                    // Referees
                    // if test round spans block 1 and 2 referees only get a break after block 2
                    if ($j_rounds < 5 || $c_block > 1) {

                        $r_next = clone $r_next;
                        g_add_minutes($r_next, $r_duration_break);

                    } else {

                        $r_next = clone $r_time;
                    }
                }

            } // lunch break or normal break?

            g_debug_timing("Nach Break");

        } // for block ...

        g_debug_timing("Forschung Vorher");

        // All blocks done, but not necessarily in sync

        // No need to wait for judges filling sheets after teams have left
        $c_time = clone $j_time;
        g_add_minutes($c_time, -$j_duration_scoring);

        // If RG has four table we need to wait for the second pair
        if ($r_tables == 4) {
            g_add_minutes($r_time, $r_duration_next_start);
        }

        // Witrh robot check we need to add that too.
        if ($r_robot_check) {
            g_add_minutes($r_time, $r_duration_robot_check);
        }

        // If RG is later, their time wins
        if ($r_time > $c_time) {
            $c_time = clone $r_time;
        }

        g_debug_timing("Forschung nachher");

    } // $c_teams > 0

    // FLL Challenge judging and RG is done. May include FLL Explore morning batch


    // -----------------------------------------------------------------------------------
    // FLL Explore Afternoon Batch
    // -----------------------------------------------------------------------------------


    if ($e2_teams > 0) {

        if ($DEBUG >= 1) {
            echo "<h2>FLL Explore - Afternoon Batch</h2>";
            echo "e2 Teams: $e2_teams<br>";
            echo "e2 Lanes: $e2_lanes<br>";
            echo "e2 Rounds: $e2_rounds<br>";
            echo "<br>";
        }

        e_judging($e_teams, $e_lanes, $e_rounds, $e_duration_with_team, $e_duration_scoring, $e_duration_break, $e_time);

        // TODO move the below to the function, after expert parameters are in one array

        // Buffer before all judges meet for deliberations
        g_add_minutes($e_time, $e_ready_deliberations);

        // Time for deliberations depends mostly on number of teams
        // TODO expert parameters? dyn
        if ($e2_teams <= 10) {
            // $e_duration_deliberations = 30;
        } else {
            // $e_duration_deliberations = 45;
        }

        // Deliberations
        $g_activity_group = db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
        db_insert_activity($g_activity_group, ID_ATD_E_DELIBERATIONS, $e_time, $e_duration_deliberations, 0, 0);
        g_add_minutes($e_time, $e_duration_deliberations);

        // FLL Explore afternoon is done

        // If combined with FLL Challenge, wait for the joint awards ceremony

    } else {

        if ($DEBUG >= 1) {
            echo "<h2>No FLL Explore afternoon batch</h2>";
        }

    }

    // No Challenge --> Explore stand-alone awards either morning or afternoon batch

    if ($c_teams == 0 && ($e1_teams > 0 || $e2_teams > 0)) {

        // Get ready e.g. judges get on stage
        g_add_minutes($e_time, $e_ready_awards);

        // Add FLL Explore Awards
        $g_activity_group = db_insert_activity_group(ID_ATD_E_AWARDS);
        db_insert_activity($g_activity_group, ID_ATD_E_AWARDS, $e_time, $e_duration_awards);
        g_add_minutes($e_time, $e_duration_awards);

    }

    if ($c_teams > 0) {
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
        g_add_minutes($j_time, $j_ready_deliberations);

        // Deliberation
        $g_activity_group = db_insert_activity_group(ID_ATD_C_DELIBERATIONS);
        db_insert_activity($g_activity_group, ID_ATD_C_DELIBERATIONS, $j_time, $j_duration_deliberations);
        g_add_minutes($j_time, $j_duration_deliberations);

        // -----------------------------------------------------------------------------------
        // Special for D-A-CH finale Siegen 2025: Move the next to another day.
        // -----------------------------------------------------------------------------------

        if ($g_finale && $g_days == 3) {

            // Debriefing for referees
            g_add_minutes($r_time, $r_duration_break);
            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_DEBRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_DEBRIEFING, $r_time, $r_duration_debriefing, 0, 0, 0, 0, 0, 0);

            // Move to next day

            list($hours, $minutes) = explode(':', $f_start_opening_day_3);
            $c_time->setTime((int)$hours, (int)$minutes);
            $c_time->modify('+1 day');

            // Additional short referee briefing
            $t = clone $c_time;
            g_add_minutes($t, -1 * ($r_duration_briefing_2 + $c_ready_opening));
            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $t, $r_duration_briefing_2);

            // Small opening day 3
            $g_activity_group = db_insert_activity_group(ID_ATD_C_OPENING_DAY_3);
            db_insert_activity($g_activity_group, ID_ATD_C_OPENING_DAY_3, $c_time, $f_duration_opening_day_3);
            g_add_minutes($c_time, $f_duration_opening_day_3);

            // Buffer between opening and first action for teams and judges
            g_add_minutes($c_time, $f_ready_action_day_3);

        }

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------

        // Organizer may chose not to show any presentations.
        // They can also decide to show them at the end

        if ($c_presentations == 0 || $c_presentations_last) {

            // No presentations at all or at the end. We run robot game finals first
            $r_time = clone $c_time;

            // Break needed when there is no third day
            if ($g_days < 3) {

                // Break for referees
                g_add_minutes($r_time, $r_duration_break);

                // Additional 5 minutes to show who advances and for those teams to get ready
                g_add_minutes($r_time, $r_duration_results);

            }

        } else {

            // Duration:
            // 5 minutes for each presentation
            // Buffer before and after to organized in the room
            // Additional buffer, because team will likely overrun the 5 minutes.

            // Check if an extra block is inserted. If so, get the total duration back.
            $duration = db_get_duration_inserted_activity(ID_IP_PRESENTATIONS);

            if ($duration > 0) {

                // Additional block for this insert point
                $g_activity_group = db_insert_activity_group(ID_ATD_C_INSERTED);
                db_insert_extra_activity($g_activity_group, ID_ATD_C_INSERTED, $c_time, ID_IP_PRESENTATIONS);

                g_add_minutes($c_time, $duration);

            } else {

                // No extra block, but time to transition to presentations

                // Transition needed only when day was not split
                if ($g_days < 3) {

                    // from robot game to first presentation
                    g_add_minutes($c_time, $c_ready_presentations);
                }

            }

            $g_activity_group = db_insert_activity_group(ID_ATD_C_PRESENTATIONS);

            for ($p = 1; $p <= $c_presentations; $p++) {
                db_insert_activity($g_activity_group, ID_ATD_C_PRESENTATIONS, $c_time, $c_duration_presentation);
                g_add_minutes($c_time, $c_duration_presentation);
            }

            // back to robot game
            g_add_minutes($c_time, $c_ready_presentations);

            // As if now nothing runs in parallel to robot game, but we use r_time anyway to be more open for future changes
            $r_time = clone $c_time;

            // Additional 5 minutes to show who advances and for those teams to get ready
            g_add_minutes($r_time, $r_duration_results);

        }

        // -----------------------------------------------------------------------------------
        /// Robot-game final rounds
        // -----------------------------------------------------------------------------------

        // Check if an extra block is inserted. If so, get the total duration back.
        $duration = db_get_duration_inserted_activity(ID_IP_RG_FINALS);

        if ($duration > 0) {

            // Additional block for this insert point
            $g_activity_group = db_insert_activity_group(ID_ATD_C_INSERTED);
            db_insert_extra_activity($g_activity_group, ID_ATD_C_INSERTED, $r_time, ID_IP_RG_FINALS);

            g_add_minutes($r_time, $duration);

        }

        // TODO - No teams given in matches. Should reference the ranking from previous rounds e.g. "4. RG" or "3. VF".


        // Round of 16 (= 8 matches) is only an option for a D-A-CH final and will require 4 tables.

        if ($g_finale && $g_days == 3 && $r_tables == 4) {

            $g_activity_group = db_insert_activity_group(ID_ATD_R_FINAL_16);

            // 4 tables = alternating between tables

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
            g_add_minutes($r_time, $r_duration_match);

            // Additional 5 minutes to show who advances and for those teams to get ready
            g_add_minutes($r_time, $r_duration_results);

            // If robot check is on, the first match was delayed by the first check.
            // Need to add that time before going on
            if ($r_robot_check) {
                g_add_minutes($r_time, $r_duration_robot_check);
            }

        }


        // Quarter final (= 4 matches)

        // RP can deselect the QF. And we only allow it with at least 8 teams
        // If round of 16 is on, this must be on too.
        if ((($g_finale && $g_days == 3 && $r_tables == 4) || $r_quarter_final) && $c_teams >= 8) {

            // Quarter final = 8 teams left
            $g_activity_group = db_insert_activity_group(ID_ATD_R_FINAL_8);

            // TODO texts: RG1, RG2, RG3, ... RG8

            if ($r_tables == 2) {

                // 3 tables = matches in sequence

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

            } else {

                // 4 tables = alternating between tables

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_next_start);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_next_start);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_next_start);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

            }

            // Additional 5 minutes to show who advances and for those teams to get ready
            g_add_minutes($r_time, $r_duration_results);
        }

        // If robot check is on, the first match was delayed by the first check.
        // Need to add that time before going on
        if ($r_robot_check) {
            g_add_minutes($r_time, $r_duration_robot_check);
        }


        // Semi final = 4 teams left
        $g_activity_group = db_insert_activity_group(ID_ATD_R_FINAL_4);

        // Texts differ depening on if there was a QF, but it will take place in both case

        if ($r_quarter_final && $c_teams >= 8) {

            // TODO texts: QF1, QF2, QF3, QF4

            if ($r_tables == 2) {

                // 2 tables = matches in sequence

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);


            } else {

                // 4 tables = alternating between tables

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_next_start);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

            }

        } else {

            // TODO texts: RG1, RG2, RG3, RG4

            if ($r_tables == 2) {

                // 2 tables = matches in sequence

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);


            } else {

                // 4 tables = alternating between tables

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_next_start);

                r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 3, 0, 4, 0, $r_robot_check);
                g_add_minutes($r_time, $r_duration_match);

            }

        }

        // If robot check is on, the first match was delayed by the first check.
        // Need to add that time before going on
        if ($r_robot_check) {
            g_add_minutes($r_time, $r_duration_robot_check);
        }


        // Insert Point
        // Check if an extra block is inserted. If so, get the total duration back.
        $duration = db_get_duration_inserted_activity(ID_IP_RG_LAST_MATCHES);

        if ($duration > 0) {

            // Additional block for this insert point
            $g_activity_group = db_insert_activity_group(ID_ATD_C_INSERTED);
            db_insert_extra_activity($g_activity_group, ID_ATD_C_INSERTED, $r_time, ID_IP_RG_LAST_MATCHES);

            g_add_minutes($r_time, $duration);

        } else {

            // Additional 5 minutes to show who advances and for those teams to get ready
            g_add_minutes($r_time, $r_duration_results);
        }


        // Extra special for Siegen 2025: Research first, final round after

        // TODEL after Siegen

        $g_activity_group = db_insert_activity_group(ID_ATD_C_PRESENTATIONS);

        for ($p = 1; $p <= $c_presentations; $p++) {
            db_insert_activity($g_activity_group, ID_ATD_C_PRESENTATIONS, $r_time, $c_duration_presentation);
            g_add_minutes($r_time, $c_duration_presentation);
        }

        // from robot game to first presentation
        g_add_minutes($r_time, $c_ready_presentations);

        // end TODEL

        // Final = 2 teams left
        $g_activity_group = db_insert_activity_group(ID_ATD_R_FINAL_2);

        // 2 matches in sequence flipping the teams

        r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, $r_robot_check);
        g_add_minutes($r_time, $r_duration_match);

        // If robot check is on, the match was delayed by the first check.
        // Need to add that time before going on
        if ($r_robot_check) {
            g_add_minutes($r_time, $r_duration_robot_check);
        }

        r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_match, 1, 0, 2, 0, 0); // only match without robot check
        g_add_minutes($r_time, $r_duration_match);

        // back to only one action a time
        $c_time = clone $r_time;


        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------

        if ($c_presentations_last && false)   // TODEL after Siegen
        {

            // Duration:
            // 5 minutes for each presentation
            // Buffer before and after to organized in the room
            // Additional buffer, because team will likely overrun the 5 minutes.


            // Check if an extra block is inserted. If so, get the total duration back.
            $duration = db_get_duration_inserted_activity(ID_IP_PRESENTATIONS);

            if ($duration > 0) {

                // Additional block for this insert point
                $g_activity_group = db_insert_activity_group(ID_ATD_C_INSERTED);
                db_insert_extra_activity($g_activity_group, ID_ATD_C_INSERTED, $c_time, ID_IP_PRESENTATIONS);

                g_add_minutes($c_time, $duration);

            } else

                // No extra block, but time to transition to presentations

                // from robot game to first presentation
                g_add_minutes($c_time, $c_ready_presentations);

            $g_activity_group = db_insert_activity_group(ID_ATD_C_PRESENTATIONS);

            for ($p = 1; $p <= $c_presentations; $p++) {
                db_insert_activity($g_activity_group, ID_ATD_C_PRESENTATIONS, $c_time, $c_duration_presentation);
                g_add_minutes($c_time, $c_duration_presentation);
            }

        }

        // -----------------------------------------------------------------------------------
        // Awards
        // -----------------------------------------------------------------------------------

        // FLL Challenge
        // Deliberations might have taken longer, which is unlikely
        if (g_diff_in_minutes($j_time, $c_time) > 0) {
            $c_time = clone $j_time;
        }

        // Check if an extra block is inserted. If so, get the total duration back.
        $duration = db_get_duration_inserted_activity(ID_IP_AWARDS);

        if ($duration > 0) {

            // Additional block for this insert point
            $g_activity_group = db_insert_activity_group(ID_ATD_C_INSERTED);
            db_insert_extra_activity($g_activity_group, ID_ATD_C_INSERTED, $c_time, ID_IP_AWARDS);

            g_add_minutes($c_time, $duration);

        } else {

            // No extra block, but time to transition to awards

            // Getting ready for awards
            g_add_minutes($c_time, $c_ready_awards);

        }

        // FLL Explore
        // Deliberations might have taken longer. Which is rather theroritical ...
        if ($e2_teams > 0 && g_diff_in_minutes($e_time, $c_time) > 0) {
            $c_time = clone $e_time;
        }


        // Awards

        if ($e2_teams > 0) {

            // Joint with Explore

            $g_activity_group = db_insert_activity_group(ID_ATD_AWARDS);
            db_insert_activity($g_activity_group, ID_ATD_AWARDS, $c_time, $c_duration_awards + $e_duration_awards);
            g_add_minutes($c_time, $c_duration_awards + $e_duration_awards);

        } else {

            // Only Challenge

            $g_activity_group = db_insert_activity_group(ID_ATD_C_AWARDS);
            db_insert_activity($g_activity_group, ID_ATD_C_AWARDS, $c_time, $c_duration_awards);
            g_add_minutes($c_time, $c_duration_awards);

        }

        // -----------------------------------------------------------------------------------
        // FLL Explore decoupled from FLL Challenge
        // -----------------------------------------------------------------------------------

        if ($e_mode == ID_E_INDEPENDENT) {

            if ($DEBUG >= 1) {
                echo "<h2>FLL Explore - Independent</h2>";
                echo "e Teams: $e_teams<br>";
                echo "e Lanes: $e_lanes<br>";
                echo "e Rounds: $e_rounds<br>";
                echo "<br>";
            }

            // Get date and time for FLL Explore
            $e_time = clone $g_event_date;
            list($hours, $minutes) = explode(':', $e_start_opening);
            $e_time->setTime((int)$hours, (int)$minutes);

            // Briefings

            // FLL Explore Coaches

            $t = clone $e_time;
            g_add_minutes($t, -1 * ($e_duration_briefing_t + $e_ready_opening));

            $g_activity_group = db_insert_activity_group(ID_ATD_E_COACH_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_E_COACH_BRIEFING, $t, $e_duration_briefing_t);

            // FLL Explore Judges

            if (!$e_briefing_after_opening_j) {

                // Briefing before opening

                $t = clone $e_time;
                g_add_minutes($t, -1 * ($e_duration_briefing_j + $e_ready_opening));

                $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
                db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $t, $e_duration_briefing_j);

            }


            // Opening
            $g_activity_group = db_insert_activity_group(ID_ATD_E_OPENING);
            db_insert_activity($g_activity_group, ID_ATD_E_OPENING, $e_time, $e_duration_opening);
            g_add_minutes($e_time, $e_duration_opening);

            if ($e_briefing_after_opening_j) {

                // Briefing after opening

                g_add_minutes($e_time, $e_ready_briefing);

                $g_activity_group = db_insert_activity_group(ID_ATD_E_JUDGE_BRIEFING);
                db_insert_activity($g_activity_group, ID_ATD_E_JUDGE_BRIEFING, $e_time, $e_duration_briefing_j);
                g_add_minutes($e_time, $e_duration_briefing_j);

            }


            // Teams and judges get ready for action
            g_add_minutes($e_time, $e_ready_action);

            // Judging
            e_judging($e_teams, $e_lanes, $e_rounds, $e_duration_with_team, $e_duration_scoring, $e_duration_break, $e_time);

            // Buffer before all judges meet for deliberations
            g_add_minutes($e_time, $e_ready_deliberations);

            // Deliberations
            $g_activity_group = db_insert_activity_group(ID_ATD_E_DELIBERATIONS);
            db_insert_activity($g_activity_group, ID_ATD_E_DELIBERATIONS, $e_time, $e_duration_deliberations, 0, 0);
            g_add_minutes($e_time, $e_duration_deliberations);

            // Awards

            // Get ready e.g. judges get on stage, RG teams and refree move away ...
            g_add_minutes($e_time, $e_ready_awards);

            // Add FLL Explore Awards
            $g_activity_group = db_insert_activity_group(ID_ATD_E_AWARDS);
            db_insert_activity($g_activity_group, ID_ATD_E_AWARDS, $e_time, $e_duration_awards);
            g_add_minutes($e_time, $e_duration_awards);

            // FLL Explore indepent is done

        } else {

            if ($DEBUG >= 1) {
                echo "<h2>No independent FLL Explore</h2>";
            }

        }


        // -----------------------------------------------------------------------------------
        // Finale has an extra day for Live Challenge and RG test rounds
        // -----------------------------------------------------------------------------------

        if ($g_finale) {

            // Only for the D-A-CH final we run the Live Challenge
            // This is done on the day before the regular event
            // Teams get extra time with the same judges they meet during the regular event
            // In parallel test rounds for robot game are run

            // -----------------
            // Live Challenge
            // -----------------

            // Default is a short break between briefings and first round.
            // It may be replaced by a custom slot for light weight opening ceremony

            // LC day was already set at the beginning of this script

            // Adjust the time
            list($hours, $minutes) = explode(':', $f_start_opening_day_1);
            $lc_time->setTime((int)$hours, (int)$minutes);

            // Continue going back in time for the briefings

            // LC Judges
            $t = clone $lc_time;
            g_add_minutes($t, -1 * ($lc_duration_briefing + $c_ready_opening));
            $g_activity_group = db_insert_activity_group(ID_ATD_LC_JUDGE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_LC_JUDGE_BRIEFING, $t, $lc_duration_briefing);

            // FLL Challenge Coaches
            $t = clone $lc_time;
            g_add_minutes($t, -1 * ($c_duration_briefing + $c_ready_opening));
            $g_activity_group = db_insert_activity_group(ID_ATD_C_COACH_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_C_COACH_BRIEFING, $t, $c_duration_briefing);

            // FLL Challenge Referees
            $t = clone $lc_time;
            g_add_minutes($t, -1 * ($r_duration_briefing + $c_ready_opening));
            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_BRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_BRIEFING, $t, $r_duration_briefing);

            // Small opening day 1
            $g_activity_group = db_insert_activity_group(ID_ATD_C_OPENING_DAY_1);
            db_insert_activity($g_activity_group, ID_ATD_C_OPENING_DAY_1, $lc_time, $f_duration_opening_day_1);
            g_add_minutes($lc_time, $f_duration_opening_day_1);

            // Buffer between opening and first action for teams and judges
            g_add_minutes($lc_time, $f_ready_action_day_1);

            // Same start for RG test rounds
            $r_time = clone $lc_time;

            // For judging team number are used in increasing order
            // $j_t is the first team in the block. The lane number is added to this.
            $j_t = 0;

            // Now create the blocks of LC judging with robot game test rounds aligned

            for ($c_block = 1; $c_block <= $j_rounds; $c_block++) {

                //---
                // LC Judging and Robot Game test rounds
                //---

                $g_activity_group = db_insert_activity_group(ID_ATD_LC_JUDGING_PACKAGE);

                // with team
                for ($j_l = 1; $j_l <= $j_lanes; $j_l++) {

                    // Not all lanes might be full
                    if ($j_t + $j_l <= $c_teams) {

                        db_insert_activity($g_activity_group, ID_ATD_LC_WITH_TEAM, $lc_time, $lc_duration_with_team, $j_l, $j_t + $j_l, 0, 0, 0, 0);

                    }
                }
                g_add_minutes($lc_time, $lc_duration_with_team);

                // scoring without team
                for ($j_l = 1; $j_l <= $j_lanes; $j_l++) {

                    // Not all lanes might be full
                    if ($j_t + $j_l <= $c_teams) {

                        db_insert_activity($g_activity_group, ID_ATD_LC_SCORING, $lc_time, $lc_duration_scoring, $j_l, $j_t + $j_l, 0, 0, 0, 0);
                    }
                }
                g_add_minutes($lc_time, $lc_duration_scoring);

                // First team to start with in next block
                $j_t += $j_lanes;

                // ***
                // Breaks
                // ***

                if ($c_block < $j_rounds) {

                    // Judges
                    g_add_minutes($lc_time, $lc_duration_break);
                }

            } // for block ...

            // Ready for deliberations
            g_add_minutes($lc_time, $lc_ready_deliberations);

            // Deliberation
            $g_activity_group = db_insert_activity_group(ID_ATD_LC_DELIBERATIONS);
            db_insert_activity($g_activity_group, ID_ATD_LC_DELIBERATIONS, $lc_time, $lc_duration_deliberations);
            g_add_minutes($lc_time, $lc_duration_deliberations);

            // Optional judge briefing to get ready for the main day
            // If duration is set to 0, it will be skipped

            if ($f_duration_briefing_day_1 > 0) {

                $c_time = clone $lc_time;

                // Time to switch from LC to FLL Challenge briefing
                g_add_minutes($c_time, $f_ready_briefing_day_1);

                $g_activity_group = db_insert_activity_group(ID_ATD_C_JUDGE_BRIEFING_DAY_1);
                db_insert_activity($g_activity_group, ID_ATD_C_JUDGE_BRIEFING_DAY_1, $c_time, $f_duration_briefing_day_1, 0, 0, 0, 0, 0, 0);
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
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_TEST);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 13, 2, 16, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 15, 4, 18, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 14, 2, 17, true);
            g_add_minutes($r_time, $r_duration_next_start);

            // -

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 22, 4, 23, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 19, 2, 20, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 24, 4, 21, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);


            g_add_minutes($r_time, $lc_duration_break); // Same break as lC judges to keep the flow in sync


            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 4, 2, 5, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 2, 4, 3, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 6, 2, 1, true);
            g_add_minutes($r_time, $r_duration_next_start);

            // -


            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 26, 4, 29, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 25, 2, 27, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 30, 4, 28, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            //

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 10, 2, 11, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 8, 4, 9, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 12, 2, 7, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);


            g_add_minutes($r_time, 2 * $lc_duration_break + 5); // Some fine-tuning needed

            // TR 2 of 2
            $g_activity_group = db_insert_activity_group(ID_ATD_R_ROUND_TEST);


            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 13, 4, 14, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 15, 2, 16, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 17, 4, 18, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            // -

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 1, 2, 2, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 3, 4, 4, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 5, 2, 6, true);
            g_add_minutes($r_time, $r_duration_next_start);


            g_add_minutes($r_time, $lc_duration_break); // Same break as lC judges to keep the flow in sync


            // -

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 21, 4, 22, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 23, 2, 24, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 19, 4, 20, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            // -

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 7, 2, 8, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 9, 4, 10, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 11, 2, 12, true);
            g_add_minutes($r_time, $r_duration_next_start);

            // -

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 25, 4, 26, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 1, 27, 2, 28, true);
            g_add_minutes($r_time, $r_duration_next_start);

            r_insert_one_match($g_activity_group, ID_ATD_R_MATCH, $r_time, $r_duration_test_match, 3, 29, 4, 30, true);
            g_add_minutes($r_time, $r_duration_test_match - $r_duration_next_start);

            // Debriefing for referees
            g_add_minutes($r_time, $r_duration_robot_check);
            g_add_minutes($r_time, $r_duration_break);
            $g_activity_group = db_insert_activity_group(ID_ATD_R_REFEREE_DEBRIEFING);
            db_insert_activity($g_activity_group, ID_ATD_R_REFEREE_DEBRIEFING, $r_time, $r_duration_debriefing, 0, 0, 0, 0, 0, 0);


        } // Finale


    } else {

        if ($DEBUG >= 1) {
            echo "<h2>No FLL Challenge</h2>";
        }

    } // if ($c_teams > 0)

    if ($DEBUG >= 1) {
        echo "<h2> Plan $g_plan ends {$c_time->format('d.m.Y H:i')} </h2>";
    }

    // Add all free blocks. Timing does not matter, becuase these are parallel to other activities
    db_insert_free_activities();

} // function generator()
?>
