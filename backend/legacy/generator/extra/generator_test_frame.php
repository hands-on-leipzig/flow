<?php

require_once '../generator_main.php';

echo "<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Testframe for the new FLOW</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            padding: 0;
            background-color: #f4f4f4;
            text-align: center;
        }
        .info-line {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
        }
        .form-container {
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            max-width: 700px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .form-column {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 30%;
        }
        .form-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin-bottom: 5px;
        }
        .form-row label {
            font-size: 14px;
            color: #555;
            margin-right: 5px;
            white-space: nowrap;
        }
        .form-row input {
            width: 40px;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-align: center;
        }
        .form-checkbox {
            display: flex;
            align-items: center;
        }
        .form-checkbox label {
            margin-left: 5px;
        }
        .form-column button {
            width: 100%;
            padding: 10px;
            background: #007BFF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .form-column button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>";

// Connect to DB
db_connect_persistent();

// The array to hold all the activies
global $test_plans;
$test_plans = [];

// Get the last modified date and time of the current file
$lastModified = date('F d Y H:i:s.', filemtime(__FILE__));

// Display the last modified date and time
echo "<p class='info-line'>Last modified: $lastModified</p>";

echo "<form class='form-container' method='post'>
        <div class='form-column'>
            <div class='form-row'>
                <label for='c_teams_min'>c teams MIN</label>
                <input type='text' id='c_teams_min' name='c_teams_min' maxlength='2' value='1'>
            </div>
            <div class='form-row'>
                <label for='c_teams_max'>c teams MAX</label>
                <input type='text' id='c_teams_max' name='c_teams_max' maxlength='2' value='99'>
            </div>
            <div class='form-row'>
                <label for='j_rounds'>j rounds (0 = all)</label>
                <input type='text' id='j_rounds' name='j_rounds' maxlength='1' value='4'>
            </div>
            <button type='submit' name='action' value='1'>Create supported plans</button>
        </div>
        <div class='form-column'>
            <div class='form-row'>
                <label for='c_teams'>c teams</label>
                <input type='text' id='c_teams' name='c_teams' maxlength='2' value='12'>
            </div>
            <div class='form-row'>
                <label for='j_lanes'>j lanes</label>
                <input type='text' id='j_lanes' name='j_lanes' maxlength='1' value='3'>
            </div>
            <div class='form-row'>
                <label for='r_tables'>r tables</label>
                <input type='text' id='r_tables' name='r_tables' maxlength='1' value='2'>
            </div>
            <div class='form-row'>
                <input type='checkbox' id='r_robot_check' name='r_robot_check' value='0'>
                <label for='r_robot_check'>r check</label>
            </div>
            <div class='form-row'>
                <label for='e_mode'>e mode</label>
                <input type='text' id='e_mode' name='e_mode' maxlength='1' value='0'>
            </div>
            <div class='form-row'>
                <label for='DEBUG'>DEBUG level</label>
                <input type='text' id='DEBUG' name='DEBUG' maxlength='1' value='0'>
            </div>
            <div class='form-row'>
                <input type='checkbox' id='DEBUG_RG' name='DEBUG_RG' value='yes'>
                <label for='DEBUG_RG'>DEBUG_RG</label>
            </div>
            <button type='submit' name='action' value='2'>Create plan</button>
        </div>
        <div class='form-column'>
            <div class='form-row'>
                <label for='plan_id'>Enter Plan ID:</label>
                <input type='text' id='plan_id' name='plan_id' maxlength='4'>
            </div>
            <button type='submit' name='action' value='3'>Analyze this plan</button>
        </div>
    </form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    switch($action) {

        case 1:
            // Create all supported plans
            plan_create_mass($_POST['c_teams_min'], $_POST['c_teams_max'], $_POST['j_rounds']);
            break;

        case 2:
            // Create one new plan with the given parameters
            $r_robot_check = isset($_POST['r_robot_check']) ? 1 : 0;
            $debug_rg = isset($_POST['DEBUG_RG']) ? 1 : 0;
            
            plan_create_one($_POST['c_teams'], $_POST['j_lanes'], $_POST['r_tables'], $r_robot_check, $_POST['e_mode'], $_POST['DEBUG'], $debug_rg);
            break;
            
        case 3:
            // Load the plan with the given plan_id
            plan_load($_POST['plan_id']);
            break;

    }
}

get_activities();
overview();

echo "</body>";


function plan_create_mass($c_teams_min, $c_teams_max, $only_j_rounds) {

    global $DEBUG;
    global $g_db;
    global $test_plans;

    $c_teams = 0; 
    $j_lanes = 0; 
    $r_tables = 0;
    $j_rounds = 0; 
    $r_robot_check = 0; 

    // Load all supported plans for FLL Challenge

    $sql = "SELECT teams, lanes, tables FROM m_supported_plan WHERE first_program = ? ORDER BY teams, lanes, tables";
    $stmt = $g_db->prepare($sql);

    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $first_program = ID_FP_CHALLENGE;
    $stmt->bind_param("i", $first_program);
    $stmt->execute();
    $stmt->bind_result($c_teams, $j_lanes, $r_tables);

    // Create a list of all possible plans with and without robot-check
    
    while ($stmt->fetch()) {

        $j_rounds = ceil($c_teams / $j_lanes);

        if (( $j_rounds == $only_j_rounds || $only_j_rounds == 0) &&
              $c_teams >= $c_teams_min && $c_teams <= $c_teams_max ) {

            for ($r_robot_check = 0; $r_robot_check <= 1; $r_robot_check++) {

                $test_plans[] = [
                    'id' => 0,               // will be updated after creation

                    'c_teams' => $c_teams,
                    'j_lanes' => $j_lanes,
                    'j_rounds' => $j_rounds,
                    'r_tables' => $r_tables,
                    'r_robot_check' => $r_robot_check,

                    'e_mode' => 0,   
                    'e1_teams' => 0,      
                    'e1_lanes' => 0,           
                    'e1_rounds' => 0,          
                    'e2_teams' => 0,           
                    'e2_lanes' => 0,           
                    'e2_rounds' => 0,          
                    ];
            }
        }
    }
    $stmt->close();

    // Create one new event
    $event_id = event_new(1, 1);

    // Generate all possible Challenge plans with and without robot-check

    foreach ($test_plans as &$plan) {               // & to ensure that the plan ID is updated in the array

        // Simulate the UI by create an entry in table plan and in plan_param_value

        $plan_id = plan_new($event_id,
                            $plan['c_teams'], 
                            $plan['j_lanes'],
                            $plan['r_tables'],
                            $plan['r_robot_check'],

                            $plan['e_mode'],
                            $plan['e1_teams'],
                            $plan['e1_lanes'],
                            $plan['e2_teams'],
                            $plan['e2_lanes']
                    );

        // Call the generator
        $DEBUG = 0; // Set DEBUG to 0 to avoid debug output during mass generation                         
        g_generator($plan_id);
        
        // Update the plan ID in the array
        $plan['id'] = $plan_id;

    }
    unset($plan); // Unset reference to avoid side effects

}

function plan_create_one($c_teams, $j_lanes, $r_tables, $r_robot_check, $e_mode, $d = 0, $d_rg = 0) {

    global $DEBUG;
    global $DEBUG_RG;
    global $test_plans;

    // Check if the Challeng plan is supported and get the number of jury rounds
    // $j_rounds = db_get_from_supported_plan(ID_FP_CHALLENGE, $c_teams, $j_lanes, $r_tables); // TODO

/*

    // Create one new event
    $event_id = event_new(1, 1);

    // Simulate the UI by creating an entry in table plan and in plan_param_value

    $j_rounds = ceil($c_teams / $j_lanes);

    $plan_id = plan_new($event_id,
                        $c_teams,
                        $j_lanes,
                        $r_tables,
                        $r_robot_check,
                        $e_mode);  */

    $plan_id = 4398; // for testing only

    // Set the DEBUG level
    // This is used to control the debug output in the generator
    $DEBUG = $d; 
    $DEBUG_RG = $d_rg;

    // Call the generator                          
    g_generator($plan_id);

    // Add the plan to the array
    $test_plans[] = [
        'id' => $plan_id,            

        'c_teams' => $c_teams,
        'j_lanes' => $j_lanes,
        'j_rounds' => $j_rounds, 
        'r_tables' => $r_tables,
        'r_robot_check' => $r_robot_check,

        'e_mode' => $e_mode,
        'e1_teams' => 0,
        'e1_lanes' => 0,
        'e1_rounds' => 0,
        'e2_teams' => 0,
        'e2_lanes' => 0,
        'e2_rounds' => 0
    ];

}

function plan_load($plan_id) {

    global $test_plans;

    global $g_plan; // Required to use the functiongp()

    $g_plan = $plan_id;

    // Load the plan with the given plan_id

    $test_plans[] = [
        'id' => $plan_id,            

        'c_teams' =>gp('c_teams'),
        'j_lanes' =>gp('j_lanes'),
        'j_rounds' => 0, // $j_rounds,
        'r_tables' =>gp('r_tables'),
        'r_robot_check' =>gp('r_robot_check'),

        'e_teams' =>gp('e_teams'),          
        'e_lanes' =>gp('e_lanes'),
        'e_rounds' => 0,                                           //gp('e_rounds'),
        'e_morning' =>gp('e_morning')
    ];

}


function get_activities() {

    global $g_db;
    global $test_plans;
    global $team_activities;
    global $team_robot_game;

    // Get all activities for all plans in the array

    foreach ($test_plans as $plan) {

        // Initialize arrays for team activities
        // and robot game assignments
        $team_activities = [];
        $team_robot_game = [];
        for ($i = 1; $i <= $plan['e1_teams']; $i++) {
            $team_activities[ID_FP_EXPLORE][$i] = [];
        }
        for ($i = 1; $i <= $plan['c_teams']; $i++) {
            $team_activities[ID_FP_CHALLENGE][$i] = [];
            $team_robot_game[$i]['table'] = [];
            $team_robot_game[$i]['opponent'] = [];
        }

        // Get the plan ID from array to local variable
        $plan_id = $plan['id'];

        // Collect all activities for this plan
        $query = "
        SELECT 
            a.start AS start_time, 
            a.end AS end_time, 
            a.jury_team AS jury_team,
            a.table_1_team AS table_1_team,
            a.table_2_team AS table_2_team,
            atd.id AS activity_type_detail_id,
            atd.name AS activity_name,
            atd.first_program AS first_program,
            ag.activity_type_detail AS group_atd
        FROM 
            activity a
        JOIN 
            activity_group ag ON a.activity_group = ag.id
        JOIN 
            m_activity_type_detail atd ON a.activity_type_detail = atd.id
        WHERE 
            ag.plan = ?
        ORDER BY 
            a.start ASC
        ";

        $stmt = $g_db->prepare($query);
        $stmt->bind_param('i', $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Skip activities where judges are scoring wihout the team in the room
            if (in_array($row['activity_type_detail_id'], [ID_ATD_E_SCORING, ID_ATD_C_SCORING, ID_ATD_LC_SCORING])) {
                continue;
            }

            $activity_name = $row['activity_name'];
            $start_time = new DateTime($row['start_time']);
            $end_time = new DateTime($row['end_time']);
            $formatted_start_time = $start_time->format('H:i');
            $formatted_end_time = $end_time->format('H:i');
            $activity_entry = "$formatted_start_time - $formatted_end_time: $activity_name";

            // Assign activity to jury_team
            if ($row['jury_team'] > 0) {

                $team_activities[$row['first_program']][$row['jury_team']] = [
                    'type' => $row['activity_type_detail_id'],
                    'entry' => $activity_entry,
                    'start' => $start_time,
                    'end' => $end_time
                ];
            }

            // Assign activity to table_1_team
            if ($row['table_1_team'] > 0) {
                
                $team_activities[ID_FP_CHALLENGE][$row['table_1_team']] = [
                    'type' => $row['activity_type_detail_id'],
                    'entry' => $activity_entry,
                    'start' => $start_time,
                    'end' => $end_time
                ];

                // Note table and opponent, but not for test round
                if ($row['group_atd'] <> ID_ATD_R_ROUND_TEST) { 
                    $team_robot_game[$row['table_1_team']]['table'][] = 1;
                    $team_robot_game[$row['table_1_team']]['opponent'][] = $row['table_2_team'];
                }
            }

            // Assign activity to table_2_team
            if ($row['table_2_team'] > 0) {
                $team_activities[ID_FP_CHALLENGE][$row['table_2_team']] = [
                    'type' => $row['activity_type_detail_id'],
                    'entry' => $activity_entry,
                    'start' => $start_time,
                    'end' => $end_time
                ];
            
                // Note table and opponent, but not for test round
                if ($row['group_atd'] <> ID_ATD_R_ROUND_TEST) {
                    $team_robot_game[$row['table_2_team']]['table'][] = 2;
                    $team_robot_game[$row['table_2_team']]['opponent'][] = $row['table_1_team'];
                }
            }

        }

        $stmt->close();

        // dump the array for debugging
        // print_r($team_robot_game[1]['table']); 
        // echo "<br>";
        // print_r($team_robot_game[1]['opponent']);   

    } // foreach $plans

  

}

function overview() {

    global $test_plans;

    //
    // Create an over of all plans in the array
    //

    echo "<table border='1'>
        <tr>
            <th>ID</th>
            <th>CT</th>
            <th>JL</th>
            <th>RT</th>
            <th>JR</th>            
            <th>RC</th>
            <th>EM</th>
            <th>E1T</th>
            <th>E1L</th>
            <th>E1R</th>
            <th>E2T</th>
            <th>E2L</th>
            <th>E2R</th>
            
        </tr>";

    foreach ($test_plans as $plan) {
        echo "<tr>
            <td><a href='https://dev.planning.hands-on-technology.org/generator/generator_day_plan.php?plan=" . $plan['id']  ."' target='_new'>" . $plan['id'] ."</a></td>
            <td>{$plan['c_teams']}</td>
            <td>{$plan['j_lanes']}</td>
            <td>{$plan['r_tables']}</td>
            <td>{$plan['j_rounds']}</td>
            <td>" . ($plan['r_robot_check'] ? 'Ja' : 'Nein') . "</td>
            <td>{$plan['e_mode']}</td>
            <td>{$plan['e1_teams']}</td>
            <td>{$plan['e1_lanes']}</td>
            <td>{$plan['e1_rounds']}</td>
            <td>{$plan['e2_teams']}</td>
            <td>{$plan['e2_lanes']}</td>
            <td>{$plan['e2_rounds']}</td>
        </tr>";
    }

    echo "</table>";

}



function event_new($event_level, $event_days) {

    global $g_db;

    //
    // Insert a new event with the given number of days
    //

    $event_name = "Test event (level=$event_level, days=$event_days)";
    $event_date = new DateTime('+100 days');
    
    // Prepare the SQL query
    $query = "INSERT INTO event (name, level, date, days, regional_partner) 
            VALUES (?, ?, ?, ?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('sisii', $event_name, $event_level, $event_date->format('Y-m-d'), $event_days, 1);

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the insert ID
    $event_id = $stmt->insert_id;

    // Debug
    // echo "<br> New event $event_id: $event_name";

    return $event_id;
}


function plan_new($event_id, $c_teams, $j_lanes, $r_tables, $r_robot_check, 
                  $e_mode = 0, $e1_teams = 6, $e1_lanes = 2, $e2_teams = 11, $e2_lanes = 3) {

    global $g_db;
    $paramId = 0;

    // Set current date and time for created and last_change columns
    $now = new DateTime();

    $j_rounds = ceil($c_teams / $j_lanes); // Calculate jury rounds based on teams and lanes
    // $e1_rounds = ceil($e1_teams / $e1_lanes); // Calculate e1 rounds
    // $e2_rounds = ceil($e2_teams / $e2_lanes); // Calculate e2 rounds

    // Create the name
    $plan_name = "Test Plan Challenge: {$c_teams}-{$j_lanes}-{$r_tables}({$j_rounds}) RC " . ($r_robot_check ? "an" : "aus"); 

    // Prepare the SQL query
    $query = "INSERT INTO plan (name, event, created, last_change) 
            VALUES (?, ?, ?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('siss', $plan_name, $event_id, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the insert ID
    $plan_id = $stmt->insert_id;

    // Close the statement
    $stmt->close();

    // Add detailed parameters to table plan_param_value

    // Define the parameters and their values
    $params = [
        ['parameter' => 'c_teams', 'value' => $c_teams],
        ['parameter' => 'j_lanes', 'value' => $j_lanes],
        ['parameter' => 'r_tables', 'value' => $r_tables],
        ['parameter' => 'r_robot_check', 'value' => $r_robot_check],
        ['parameter' => 'e_mode', 'value' => $e_mode],
        ['parameter' => 'e1_teams', 'value' => $e1_teams],
        ['parameter' => 'e1_lanes', 'value' => $e1_lanes],
        ['parameter' => 'e2_teams', 'value' => $e2_teams],
        ['parameter' => 'e2_lanes', 'value' => $e2_lanes]
    ];

    // Lookup parameter IDs from m_parameter table and store in a map to avoid repeated prepares
    $param_ids = [];
    $query = "SELECT id FROM m_parameter WHERE name = ?";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }
    foreach ($params as $param) {
        $param_name = $param['parameter'];
        $stmt->bind_param('s', $param_name);
        $stmt->execute();
        $stmt->bind_result($paramId);
        if ($stmt->fetch()) {
            $param_ids[$param_name] = $paramId;
        } else {
            die("Parameter name not found: $param_name");
        }
        $stmt->free_result();
    }
    $stmt->close();

    // Insert each parameter into the table with the looked-up ID
    $query = "INSERT INTO plan_param_value (plan, parameter, set_value) VALUES (?, ?, ?)";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }
    foreach ($params as $param) {
        $param_id = $param_ids[$param['parameter']];
        // Determine the type for set_value: 'i' for integer, 's' for string
        $type = is_int($param['value']) ? 'i' : 's';
        $stmt->bind_param("ii{$type}", $plan_id, $param_id, $param['value']);
        $stmt->execute();
        if ($stmt->error) {
            die("Execute failed: " . $stmt->error);
        }
    }

    // Close the statement
    $stmt->close();

    return $plan_id;
}

?>
