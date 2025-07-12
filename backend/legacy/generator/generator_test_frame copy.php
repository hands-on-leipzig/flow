<?php

require_once 'generator_db.php';
require_once 'generator_functions.php';

echo "<html><head><title>Test Frame</title><style>
    .table-container {
        display: flex;
        justify-content: flex-start; /* Align tables to the far left */
    }
    .table-container > div {
        margin-right: 20px; /* Maintain space between the two tables */
    }
    .table-container table {
        margin: 0;
    }
    .buttons-container {
        margin-top: 20px; /* Add space between the table and buttons */
    }
    .buttons-container button {
        margin-right: 10px; /* Add space between the buttons */
    }
</style></head><body>";

// Connect to DB
db_connect_persistent();

// Get the last modified date and time of the current file
$lastModified = date("F d Y H:i:s.", filemtime(__FILE__));

// Display the last modified date and time
echo "<p>Last modified: $lastModified</p>";

// Start outputting the form and container
echo '<form id="planForm" method="POST">';
echo '<div class="table-container">';

// FLL Challenge Table
echo '<div>';
echo '<h2>FLL Challenge</h2>';

// Initialize the array with a zero row
$plans = [
    [
        'teams' => 0,
        'lanes' => 0,
        'tables' => 0,
        'jury_rounds' => 0,
    ]
];

$sql = "SELECT teams, lanes, tables, jury_rounds FROM m_supported_plan WHERE first_program = ? ORDER BY teams, lanes, tables";
$stmt = $g_db->prepare($sql);

if ($stmt === false) {
    die("Prepare failed: " . $g_db->error);
}

$first_program = 3;
$stmt->bind_param("i", $first_program);
$stmt->execute();
$stmt->bind_result($c_teams, $j_lanes, $r_tables, $j_rounds);

$index = 0;
$defaultSelectedIndex = -1;

while ($stmt->fetch()) {
    $plans[] = [
        'teams' => $c_teams,
        'lanes' => $j_lanes,
        'tables' => $r_tables,
        'jury_rounds' => $j_rounds,
    ];

    if ($c_teams == 10 && $j_lanes == 2 && $r_tables == 2) {
        $defaultSelectedIndex = $index + 1; // Increment because of the initial zero row
    }

    $index++;
}
$stmt->close();



// Generate the FLL Challenge select box
echo '<select name="selectedPlan">';
foreach ($plans as $idx => $plan) {
    echo '<option value="' . $idx . '"' . ($idx === $defaultSelectedIndex ? ' selected' : '') . '>';
    echo "{$plan['teams']}-{$plan['lanes']}-{$plan['tables']} ({$plan['jury_rounds']})";
    echo '</option>';
}
echo '</select>';

// Allow to turn robot check on or off
echo '<br>';
echo '<label><input type="checkbox" name="robot_check" value="0"> Robot Check</label>';

echo '</div>';

// FLL Explore Table
echo '<div>';
echo '<h2>FLL Explore</h2>';

// Initialize the array with a zero row
$explorePlans = [
    [
        'teams' => 0,
        'lanes' => 0,
        'rounds' => 0,
    ]
];

$sql = "SELECT teams, lanes, jury_rounds FROM m_supported_plan WHERE first_program = ? ORDER BY teams, lanes";
$stmt = $g_db->prepare($sql);

if ($stmt === false) {
    die("Prepare failed: " . $g_db->error);
}

$first_program = 2;
$stmt->bind_param("i", $first_program);
$stmt->execute();
$stmt->bind_result($e_teams, $e_lanes, $e_rounds);

$exploreIndex = 0;
$defaultExploreSelectedIndex = -1;

while ($stmt->fetch()) {
    $explorePlans[] = [
        'teams' => $e_teams,
        'lanes' => $e_lanes,
        'rounds' => $e_rounds,
    ];

    if ($e_teams == 5) {
        $defaultExploreSelectedIndex = $exploreIndex + 1; // Increment because of the initial zero row
    }

    $exploreIndex++;
}
$stmt->close();



// Generate the FLL Explore select box
echo '<select name="selectedExplorePlan">';
foreach ($explorePlans as $idx => $plan) {
    echo '<option value="' . $idx . '"' . ($idx === $defaultExploreSelectedIndex ? ' selected' : '') . '>';
    echo "{$plan['teams']}-{$plan['lanes']} ({$plan['rounds']})";
    echo '</option>';
}
echo '</select>';

// Checkbox for "morning"
echo '<br>';
echo '<label><input type="checkbox" name="e_morning" value="1" checked> Morning</label>';

echo '</div>';  // Close the FLL Explore div
echo '</div>';  // Close the table container div

// Output the buttons
echo '<div class="buttons-container">';
echo '<button type="submit" name="action" value="one">One plan with C+E as selected (DEBUG on)</button>';
echo '<button type="submit" name="action" value="all">All C plans with E as selected (daily plan only)</button>';
echo '</div>';

echo '</form>';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'one') {

            // One plan selected from the first table
            $selectedPlanIndex = intval($_POST['selectedPlan']);
            $selectedPlan = $plans[$selectedPlanIndex];

            // Set global variables based on the selected plan
            $c_teams = $selectedPlan['teams'];
            $j_lanes = $selectedPlan['lanes'];
            $r_tables = $selectedPlan['tables'];
            $j_rounds = $selectedPlan['jury_rounds'];

            // Set r_robot_check based on the checkbox
            $r_robot_check = isset($_POST['robot_check']) ? 1 : 0;

            // One plan selected from the second table
            $selectedExplorePlanIndex = intval($_POST['selectedExplorePlan']);
            $selectedExplorePlan = $explorePlans[$selectedExplorePlanIndex];

            // Set global variables based on the selected Explore plan
            $e_teams = $selectedExplorePlan['teams'];
            $e_lanes = $selectedExplorePlan['lanes'];
            $e_rounds = $selectedExplorePlan['rounds'];

            // Set e_morning based on the checkbox
            $e_morning = isset($_POST['e_morning']) ? 1 : 0;

            // Create dummy plan based on the selected plan
            $plan = insertPlan(
                "Test Plan {$c_teams}-{$j_lanes}-{$r_tables}({$j_rounds}) + {$e_teams}-{$e_lanes}-{$e_morning}({$j_rounds})",
                1,
                $c_teams,
                $j_lanes,
                $r_tables,
                $r_robot_check,
                $e_teams,
                $e_lanes,
                $e_morning // Use the value from the checkbox
            );

            // Call the generator
            $DEBUG = 1; // 0 = off, 1 = key topics, 2 = details
            g_generator($plan);

            // Display the generated plan
            $query_params = [
                'plan' => $plan,
            ];
            $_GET = array_merge($_GET, $query_params);
            include 'generator_team_plan.php';
            include 'generator_day_plan.php';

            // Preserve the selected plan index
            $selectedPlanIndex = intval($_POST['selectedPlan']);
            $robotCheck = isset($_POST['robot_check']) ? 'checked' : '';
            $selectedExplorePlanIndex = intval($_POST['selectedExplorePlan']);
            $eMorning = isset($_POST['e_morning']) ? 'checked' : '';

        } elseif ($action === 'all') {
            // All Challenge plans including the selected Explore plan

            foreach ($plans as $plan) {
                // Set global variables based on the current plan
                $c_teams = $plan['teams'];
                $j_lanes = $plan['lanes'];
                $r_tables = $plan['tables'];
                $j_rounds = $plan['jury_rounds'];

                // One plan selected from the second table
                $selectedExplorePlanIndex = intval($_POST['selectedExplorePlan']);
                $selectedExplorePlan = $explorePlans[$selectedExplorePlanIndex];

                // Set global variables based on the selected Explore plan
                $e_teams = $selectedExplorePlan['teams'];
                $e_lanes = $selectedExplorePlan['lanes'];
                $e_rounds = $selectedExplorePlan['rounds'];

                // Set e_morning based on the checkbox
                $e_morning = isset($_POST['e_morning']) ? 1 : 0;

                // Create dummy plan, because generator expects it to be in the db
                $plan = insertPlan(
                    "Test Plan {$c_teams}-{$j_lanes}-{$r_tables}({$j_rounds}) + {$e_teams}-{$e_lanes}-{$e_morning}({$j_rounds})",
                    1,
                    $c_teams,
                    $j_lanes,
                    $r_tables,
                    $e_teams,
                    $e_lanes,
                    $e_morning // Use the value from the checkbox
                );

                // Call the generator
                $DEBUG = 0;                            
                g_generator($plan);

                // Display the generated plan as schedule
                $query_params = [
                    'plan' => $plan,
                ];
                $_GET = array_merge($_GET, $query_params);
                include 'generator_day_plan.php';

                // Display the actitvity groups and activities in db sequence
                //$query_params = [
                //    'plan' => $plan,
                //];
                //$_GET = array_merge($_GET, $query_params);
                //include 'generator_show_plan_raw.php';


            }
        }
    }
}

function insertPlan($name, $event, $c_teams, $j_lanes, $r_tables, $r_robot_check, $e_teams, $e_lanes, $e_morning) {

    global $g_db;

    // Set current date and time for created and last_change columns
    $created = date('Y-m-d H:i:s');
    $last_change = $created;

    // Prepare the SQL query
    $query = "INSERT INTO plan (name, event) 
              VALUES (?, ?)";

    // Prepare and bind
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    // Bind parameters
    $stmt->bind_param('si', $name, $event);

    // Execute the query
    $stmt->execute();
    if ($stmt->error) {
        die("Execute failed: " . $stmt->error);
    }

    // Get the insert ID
    $plan = $stmt->insert_id;

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

    /*

    foreach ($params as $param) {
        // Process each parameter
        echo "Parameter: " . $param['parameter'] . ", Value: " . $param['value'] . "\n";
    }

    */

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

    /*

    foreach ($params as $param) {
        // Process each parameter
        echo "Parameter: " . $param['parameter'] . ", ID: " . $param['id'] . ", Value: " . $param['value'] . "\n";
    }

    */


    // Insert each parameter into the table with the looked-up ID
    $query = "INSERT INTO plan_param_value (plan, parameter, set_value) VALUES (?, ?, ?)";
    $stmt = $g_db->prepare($query);
    if ($stmt === false) {
        die("Prepare failed: " . $g_db->error);
    }

    foreach ($params as $param) {
        $stmt->bind_param('iis', $plan, $param['id'], $param['value']);
        $stmt->execute();
        if ($stmt->error) {
            die("Execute failed: " . $stmt->error);
        }
    }

    // Close the statement
    $stmt->close();

    // Debug
    echo "<br> New plan $plan '$name'";
    
    return $plan;
}

?>
