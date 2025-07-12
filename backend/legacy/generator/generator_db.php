<?php

// ***********************************************************************************
// DB abstraction layer
// ***********************************************************************************

function db_connect_persistent() {

    global $g_db;

    // Database configuration
    if (file_exists("../conf.php")) {
        require_once "../conf.php";
    } else {
        require_once "conf.php";
    }

    static $connection;

    if ($connection === null) {
        // Create a new connection
        $connection = new mysqli(DB_HOST, DB_USER, DB_PW, DB_NAME);

        // Check connection
        if ($connection->connect_error) {
            die("Connection failed: " . $connection->connect_error);
        }
    }

    mysqli_set_charset($connection, "utf8mb4");

    $g_db = $connection;
}


function db_disconnect_persistent() {
    
    global $g_db;
    
    if ($g_db !== null) {
        $g_db->close();
    }
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
define('ID_IP_PRESENTATIONS', 1);
define('ID_IP_RG_FINALS', 2);
define('ID_IP_AWARDS', 3);
define('ID_IP_RG_LAST_MATCHES', 4);

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
define('ID_E_MORNING', 1);                              // joint opening, separate awards
define('ID_E_AFTERNOON', 2);                            // separate opening, joint awards
define('ID_E_INDEPENDENT', 3);                          // independent opening and awards


// ***********************************************************************************
// Reading from and adding to db tables
// ***********************************************************************************


// Function to get the value of a parameter directly from the database
function g_pv($parameterName)
{
    global $g_db, $g_plan;

    // Step 1: Retrieve the parameter ID from m_parameter
    $query = "SELECT id, value FROM m_parameter WHERE name = ?";
    $stmt = $g_db->prepare($query);
    $stmt->bind_param('s', $parameterName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Parameter not found in m_parameter
        die("Error: Parameter '{$parameterName}' not found in m_parameter.");
    }

    $paramRow = $result->fetch_assoc();
    $parameterId = $paramRow['id'];
    $fallbackValue = $paramRow['value']; // Fallback value from m_parameter

    $stmt->close();

    // Step 2: Retrieve the value from plan_param_value using the parameter ID and the current plan
    $query = "SELECT set_value FROM plan_param_value WHERE parameter = ? AND plan = ?";
    $stmt = $g_db->prepare($query);
    $stmt->bind_param('ii', $parameterId, $g_plan);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Found in plan_param_value
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['set_value'];
    }

    $stmt->close();

    // Step 3: Fallback to value from m_parameter if available
    if (!is_null($fallbackValue)) {
        return $fallbackValue;
    }

    // Step 4: If all else fails, die with an error
    die("Error: Parameter '{$parameterName}' not found in table plan_param_value or any fallback.");
}



function db_get_from_plan(&$g_event) {

    global $DEBUG;
    global $g_db, $g_plan;

    $sql = "SELECT event FROM plan WHERE id = ?";
    $stmt = $g_db->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $stmt->bind_param("i", $g_plan);
    $stmt->execute();
    $stmt->bind_result($g_event);

    if ($stmt->fetch()) {
        // Fetch succeeded, variables are populated
        if ($DEBUG >= 2) {
            echo "<h3>From plan</h3>";
            echo "g_event: $g_event<br><br>";
        }
    } else {
        // Fetch failed, likely no data was returned
        echo "<h3>No data found for plan ID $g_plan</h3>";
    }

    // Close the statement
    $stmt->close();
}


function db_get_from_event($g_event, &$g_event_date, &$g_days, &$g_finale) {

    global $DEBUG;
    global $g_db;
    
    // Prepare the SQL query
    $query = "SELECT date, enddate, level FROM event WHERE id = ?";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('i', $g_event);

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the result
    $stmt->bind_result($event_date, $end_date, $level);
    $stmt->fetch();

    // Close the statement
    $stmt->close();

    // Convert the date to DateTime object
    $g_event_date = new DateTime($event_date); 
    $end_date_obj = new DateTime($end_date);

    // Calculate the number of days between date and enddate
    $interval = $g_event_date->diff($end_date_obj);
    $g_days = $interval->days + 1; // Including the start date

    $g_finale = ($level == 3);

    if($DEBUG >= 2) {
        echo "<h3>From event</h3>";
        echo "g_event_date: " . $g_event_date->format('d.m.Y') . "<br>";
        echo "g_days: $g_days<br>";
        echo "g_finale: " . ($g_finale ? 'true' : 'false') . "<br>";
    }
}

function db_get_from_supported_plan($first_program, $teams, $lanes, $tables = null) {
    
    global $DEBUG;
    global $g_db;

    // Prepare the SQL query
    $query = "SELECT jury_rounds FROM m_supported_plan WHERE first_program = ? AND teams = ? AND lanes = ?";
    
    // Check if tables is provided
    if ($tables !== null) {
        $query .= " AND tables = ?";
    }
    
    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    if ($tables !== null) {
        $stmt->bind_param('iiii', $first_program, $teams, $lanes, $tables);
    } else {
        $stmt->bind_param('iii', $first_program, $teams, $lanes);
    }

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the result
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        // Bind result variables
        $stmt->bind_result($jury_rounds);
        $stmt->fetch();
        $stmt->close();

        if($DEBUG >= 2) {
            echo "<h3>From m_supported_plan</h3>";
            if($first_program == ID_FP_CHALLENGE) {
                echo "j_rounds: $jury_rounds<br>";
            } else {
                echo "e_rounds: $jury_rounds<br>";
            }
            
        }

        return $jury_rounds;

    } else {
        $stmt->close();
        if ($tables !== null) {
            die("Error: No entry found for first_program=$first_program, teams=$teams, lanes=$lanes, tables=$tables");
        } else {
            die("Error: No entry found for first_program=$first_program, teams=$teams, lanes=$lanes");
        }
    }
}

function db_insert_activity_group($activity_type_detail) {

    global $g_db, $g_plan;

    global $g_activity_group;

    // Prepare the SQL query
    $query = "INSERT INTO activity_group (plan, activity_type_detail) 
              VALUES (?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('ii', $g_plan, $activity_type_detail);

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the insert ID
    $insertId = $stmt->insert_id;

    // Close the statement
    $stmt->close();

    // Store the ID so that activities can be added easily
    $g_activity_group = $insertId;

    return $g_activity_group;
}

function db_insert_activity($g_activity_group, $activity_type_detail, DateTime $time_start, $duration, $jury_lane = Null, $jury_team = NULL,
$table_1 = Null, $table_1_team = Null, $table_2 = Null, $table_2_team = Null) {

    global $g_db;

    // Calculate end of activity
    $time_end = clone $time_start; // Clone the datetime object to prevent modification of original
    g_add_minutes($time_end, $duration);

    // Convert to strings
    $start = $time_start->format('Y-m-d H:i:s');
    $end = $time_end->format('Y-m-d H:i:s');

    // Convert to strings
    $start = $time_start->format('Y-m-d H:i:s');
    $end = $time_end->format('Y-m-d H:i:s');
  
    // Prepare the SQL query
    if ($jury_lane > 0) {

        // Judging = ignore RG
        $query = "INSERT INTO activity (activity_group, activity_type_detail, start, end, room_type, jury_lane, jury_team) 
        VALUES (?, ?, ?, ?, ?, ?, ?)";

        // Determine the room type

        switch($activity_type_detail){

            case ID_ATD_C_WITH_TEAM:
            case ID_ATD_C_SCORING:

                // FLL Challenge

                switch($jury_lane) {

                    case 1:
                        $room_type = ID_RT_C_LANE_1;
                        break;
        
                    case 2:
                        $room_type = ID_RT_C_LANE_2;
                        break;
        
                    case 3:
                        $room_type = ID_RT_C_LANE_3;
                        break;
        
                    case 4:
                        $room_type = ID_RT_C_LANE_4;
                        break;
        
                    case 5: 
                        $room_type = ID_RT_C_LANE_5;
                        break;
        
                    case 6:
                        $room_type = ID_RT_C_LANE_6;
                        break;
                }               
            break;

            case ID_ATD_E_WITH_TEAM:
            case ID_ATD_E_SCORING:

                // FLL Explore

                switch($jury_lane) {

                    case 1:
                        $room_type = ID_RT_E_LANE_1;
                        break;
        
                    case 2:
                        $room_type = ID_RT_E_LANE_2;
                        break;
        
                    case 3:
                        $room_type = ID_RT_E_LANE_3;
                        break;
        
                    case 4:
                        $room_type = ID_RT_E_LANE_4;
                        break;
        
                    case 5:
                        $room_type = ID_RT_E_LANE_5;
                        break;
        
                    case 6:
                        $room_type = ID_RT_E_LANE_6;
                        break;
                }               

            break;    

            case ID_ATD_LC_WITH_TEAM:
            case ID_ATD_LC_SCORING:

                // FLL Challenge

                switch($jury_lane) {

                    case 1:
                        $room_type = ID_RT_LC_1;
                        break;
        
                    case 2:
                        $room_type = ID_RT_LC_2;
                        break;
        
                    case 3:
                        $room_type = ID_RT_LC_3;
                        break;
        
                    case 4:
                        $room_type = ID_RT_LC_4;
                        break;
        
                    case 5: 
                        $room_type = ID_RT_LC_5;
                        break;
        
                    case 6:
                        $room_type = ID_RT_LC_6;
                        break;
                }               
            break;

        }


    } elseif ($table_1 > 0) {

        //Check Check or Robot Game = ignore judging
        $query = "INSERT INTO activity (activity_group, activity_type_detail, start, end, room_type, table_1, table_1_team, table_2, table_2_team)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        // Roome type Robot Game
        $room_type = ID_RT_R_MATCH;

    } else {

        // Anything else = ignore both
        $query = "INSERT INTO activity (activity_group, activity_type_detail, start, end, room_type) 
        VALUES (?, ?, ?, ?, ?)";   
        

        switch($activity_type_detail){

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

            case ID_ATD_OPENING:
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

    }

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters        
    if ($jury_lane > 0) {

        // Judging = ignore RG
        $stmt->bind_param('iissiii', $g_activity_group, $activity_type_detail, $start, $end, $room_type, $jury_lane, $jury_team);

    } elseif ($table_1 > 0) {

        // Robot Game = ignore judging

        // Make sure that 0 is turned into NULL.
        $table_1_team = ($table_1_team == 0) ? null : $table_1_team;
        $table_2_team = ($table_2_team == 0) ? null : $table_2_team;

        $stmt->bind_param('iissiiiii', $g_activity_group, $activity_type_detail, $start, $end, $room_type, $table_1, $table_1_team, $table_2, $table_2_team);

    } else {

        // Anything else = ignore both                                  
        $stmt->bind_param('iissi', $g_activity_group, $activity_type_detail, $start, $end, $room_type);

    }

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the insert ID
    $insertId = $stmt->insert_id;

    // Close the statement
    $stmt->close();

}

function db_get_duration_inserted_activity($insert_point) {

    global $g_db;
    global $g_plan;

    // $horst = 2018; // böse 

    $buffer_before = 0;
    $duration = 0;
    $buffer_after = 0;

    // Prepare the SQL query
    $query = "SELECT buffer_before, duration, buffer_after FROM extra_block WHERE plan = ? AND insert_point = ?";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $stmt->bind_param('ii', $g_plan, $insert_point);                // böse $g_plan
    $stmt->execute();
    $stmt->bind_result($buffer_before, $duration, $buffer_after);
    $stmt->fetch();
    $stmt->close();
    
    return $buffer_before + $duration + $buffer_after;

}

function db_insert_extra_activity($g_activity_group, $activity_type_detail, $time, $insert_point) {

    global $g_db;
    global $g_plan;

    // $horst = 2018; // böse 

    $time_start = new DateTime;
    $time_end = new DateTime;

    // Use the provided time as start time
    $time_start = clone $time;

    // Inserted Blocks have a buffer before the actual activity. This needs to be added to the start time.
    // Also we need the duration of the activity
    // Read these from table extra_block using the insert_point ID and plan ID

    $query = "SELECT id, buffer_before, duration, buffer_after FROM extra_block WHERE plan = ? AND insert_point = ?";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $stmt->bind_param('ii', $g_plan, $insert_point);                // böse $g_plan
    $stmt->execute();
    $stmt->bind_result($extra_block, $buffer_before, $duration, $buffer_after);
    $stmt->fetch();
    $stmt->close();

    // Add the buffer before the activity
    g_add_minutes($time_start, $buffer_before);                 

    // Calculate the end time
    $time_end = clone $time_start; 
    g_add_minutes($time_end, $duration);            

    // Convert to strings
    $start = $time_start->format('Y-m-d H:i:s');
    $end = $time_end->format('Y-m-d H:i:s');


    // Prepare the SQL query
    $query = "INSERT INTO activity (activity_group, activity_type_detail, start, end, extra_block) 
            VALUES (?, ?, ?, ?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $stmt->bind_param('iissi', $g_activity_group, $activity_type_detail, $start, $end, $extra_block);

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Close the statement
    $stmt->close();

}

function db_insert_free_activities() {

    global $g_db;
    global $g_plan;

    // $horst = 2018; // böse 

    // Free Blocks have a fixed duration and start time. No need to calculate anything.

    $query = "SELECT id, first_program, start, end FROM extra_block WHERE plan = ? and start IS NOT NULL";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $stmt->bind_param('i', $g_plan);                                      // böse $g_plan     
    $stmt->execute();
    $stmt->bind_result($extra_block, $first_program, $start, $end);
    $stmt->store_result();

    // Check if there are any results
    if ($stmt->num_rows > 0) {

        // Loop through all results and insert activities
        while ($stmt->fetch()) {

            switch ($first_program) {
                case ID_FP_CHALLENGE:
                    $atd = ID_ATD_C_FREE;
                    break;
                case ID_FP_EXPLORE:
                    $atd = ID_ATD_E_FREE;
                    break;
                default:
                    $atd = ID_ATD_FREE;
            }

            // Insert an activity group
            $g_activity_group = db_insert_activity_group($atd);

            // Prepare the SQL query
            $insert_query = "INSERT INTO activity (activity_group, activity_type_detail, start, end, extra_block) 
                    VALUES (?, ?, ?, ?, ?)";

            // Prepare and bind
            $stmt_insert = $g_db->prepare($insert_query);
            if ($stmt_insert === false) {
                die("Prepare failed: " . $g_db->error);
            }
            $stmt_insert = $g_db->prepare($insert_query);

            $stmt_insert->bind_param('iissi', $g_activity_group, $atd, $start, $end, $extra_block);

            // Execute the query
            $stmt_insert->execute();
            if ($stmt_insert->error) {
                die("Execute failed: " . $stmt_insert->error);
            }

            // Close the insert statement
            $stmt_insert->close();
        }
    }

    // Close the select statement
    $stmt->close();

} 

?>
