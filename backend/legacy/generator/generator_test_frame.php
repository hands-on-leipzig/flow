<?php

require_once 'generator_db.php';
require_once 'generator_functions.php';

echo "<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Testframe for FLOW</title>
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
            <button type='submit' name='action' value='1'>Create all supported plans</button>
        </div>
        <div class='form-column'>
            <div class='form-row'>
                <label for='c_teams'>C Teams:</label>
                <input type='text' id='c_teams' name='c_teams' maxlength='2'>
            </div>
            <div class='form-row'>
                <label for='j_lanes'>J Lanes:</label>
                <input type='text' id='j_lanes' name='j_lanes' maxlength='1'>
            </div>
            <div class='form-row'>
                <label for='r_tables'>R Tables:</label>
                <input type='text' id='r_tables' name='r_tables' maxlength='1'>
            </div>
            <div class='form-checkbox'>
                <input type='checkbox' id='r_robot_check' name='r_robot_check' value='0'>
                <label for='r_robot_check'>R Check</label>
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
            plan_create_mass();
            break;

        case 2:
            // Create one new plan with the given parameters
            $r_robot_check = isset($_POST['r_robot_check']) ? 1 : 0;
            plan_create_one($_POST['c_teams'], $_POST['j_lanes'], $_POST['r_tables'], $r_robot_check);
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


function plan_create_mass() {

    global $g_db;
    global $test_plans;

    // Load all supported plans for FLL Challenge

    $sql = "SELECT teams, lanes, tables, jury_rounds FROM m_supported_plan WHERE first_program = ? ORDER BY teams, lanes, tables";
    $stmt = $g_db->prepare($sql);

    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    $first_program = ID_FP_CHALLENGE;
    $stmt->bind_param("i", $first_program);
    $stmt->execute();
    $stmt->bind_result($c_teams, $j_lanes, $r_tables, $j_rounds);

    // Create a list of all possible plans with and without robot-check
    
    while ($stmt->fetch()) {

        for ($r_robot_check = 0; $r_robot_check <= 1; $r_robot_check++) {

                $test_plans[] = [
                    'id' => 0,               // will be updated after creation

                    'c_teams' => $c_teams,
                    'j_lanes' => $j_lanes,
                    'j_rounds' => $j_rounds,
                    'r_tables' => $r_tables,
                    'r_robot_check' => $r_robot_check,

                    'e_teams' => 0,           // unused for now
                    'e_lanes' => 0,           // unused for now
                    'e_rounds' => 0,          // unused for now
                    'e_morning' => true       // unused for now
                ];
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
                            $plan['e_teams'],
                            $plan['e_lanes'],
                            $plan['e_morning'] );

        // Call the generator                          
        g_generator($plan_id);
        
        // Update the plan ID in the array
        $plan['id'] = $plan_id;

    }
    unset($plan); // Unset reference to avoid side effects

}

function plan_create_one($c_teams, $j_lanes, $r_tables, $r_robot_check) {

    global $test_plans;

    // Check if the Challeng plan is supported and get the number of jury rounds
    $j_rounds = db_get_from_supported_plan(ID_FP_CHALLENGE, $c_teams, $j_lanes, $r_tables);

    // Create one new event
    $event_id = event_new(1, 1);

    // Simulate the UI by creating an entry in table plan and in plan_param_value

    $plan_id = plan_new($event_id,
                        $c_teams,
                        $j_lanes,
                        $r_tables,
                        $r_robot_check,
                        0,
                        0,
                        0 );

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

        'e_teams' => 0,           // unused for now
        'e_lanes' => 0,           // unused for now
        'e_rounds' => 0,          // unused for now
        'e_morning' => true       // unused for now
    ];

}

function plan_load($plan_id) {

    global $g_db;
    global $test_plans;

    global $g_plan; // Required to use the function g_pv()

    $g_plan = $plan_id;

    // Load the plan with the given plan_id

    // Get the number of jury rounds
    $j_rounds = db_get_from_supported_plan(ID_FP_CHALLENGE, g_pv('c_teams'), g_pv('j_lanes'), g_pv('r_tables'));

    $test_plans[] = [
        'id' => $plan_id,            

        'c_teams' => g_pv('c_teams'),
        'j_lanes' => g_pv('j_lanes'),
        'j_rounds' => $j_rounds,
        'r_tables' => g_pv('r_tables'),
        'r_robot_check' => g_pv('r_robot_check'),

        'e_teams' => g_pv('e_teams'),          
        'e_lanes' => g_pv('e_lanes'),
        'e_rounds' => 0,                                           // g_pv('e_rounds'),
        'e_morning' => g_pv('e_morning')
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
        for ($i = 1; $i <= $plan['e_teams']; $i++) {
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
            <th>ET</th>
            <th>EL</th>
            <th>ER</th>
            <th>EM</th>
        </tr>";

    foreach ($test_plans as $plan) {
        echo "<tr>
            <td>{$plan['id']}</td>
            <td>{$plan['c_teams']}</td>
            <td>{$plan['j_lanes']}</td>
            <td>{$plan['r_tables']}</td>
            <td>{$plan['j_rounds']}</td>
            <td>" . ($plan['r_robot_check'] ? 'Ja' : 'Nein') . "</td>
            <td>{$plan['e_teams']}</td>
            <td>{$plan['e_lanes']}</td>
            <td>{$plan['e_rounds']}</td>
            <td>" . ($plan['e_morning'] ? 'Ja' : 'Nein') . "</td>
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
    $event_date = new DateTime('2025-06-05');
    $event_end_date = clone $event_date;
    $event_end_date->modify("+$event_days days");

    // Prepare the SQL query
    $query = "INSERT INTO event (name, level, date, enddate) 
            VALUES (?, ?, ?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('siss', $event_name, $event_level, $event_date->format('Y-m-d'), $event_end_date->format('Y-m-d'));

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


function plan_new($event_id, $c_teams, $j_lanes, $r_tables, $r_robot_check, $e_teams, $e_lanes, $e_morning) {

    global $g_db;

    //
    // Insert a new plan with the given parameters
    //


    // Set current date and time for created and last_change columns
    $now = new DateTime();

    // Create the name
    $plan_name = "Test Plan {$c_teams}-{$j_lanes}-{$r_tables} RC " . ($r_robot_check ? "an" : "aus"); 

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
        ['parameter' => 'e_teams', 'value' => $e_teams],
        ['parameter' => 'e_lanes', 'value' => $e_lanes],
        ['parameter' => 'e_morning', 'value' => $e_morning]
    ];

    // Lookup parameter IDs from m_parameter table
    $query = "SELECT id FROM m_parameter WHERE name = ?";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    foreach ($params as &$param) {
        $stmt->bind_param('s', $param['parameter']);
        $stmt->execute();
        $stmt->bind_result($paramId);
        $stmt->fetch();
        if ($stmt->error) {
            die("Execute failed: " . $stmt->error);
        }
        $param['id'] = $paramId;
    }
    $stmt->close();

    // Insert each parameter into the table with the looked-up ID
    $query = "INSERT INTO plan_param_value (plan, parameter, set_value) VALUES (?, ?, ?)";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    foreach ($params as $param) {
        $stmt->bind_param('iis', $plan_id, $param['id'], $param['value']);
        $stmt->execute();
        if ($stmt->error) {
            die("Execute failed: " . $stmt->error);
        }
    }

    // Close the statement
    $stmt->close();

    // Debug
    // echo "<br> New plan $plan_id: $plan_name";
    
    return $plan_id;
}

?>
