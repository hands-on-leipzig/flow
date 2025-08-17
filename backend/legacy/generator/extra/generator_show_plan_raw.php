<?php
require_once '../generator_db.php';

// Get the plan parameter from the URL
$g_plan = isset($_GET['plan']) ? intval($_GET['plan']) : 0;

echo "<html><head><title>Activities raw - $g_plan</title><style>
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

// Connect to the database
db_connect_persistent();

if ($g_plan) {

    // Query to get activity groups and corresponding activities along with FIRST Program and Room Type
    $query = "
        SELECT 
            ag.id AS activity_group_id,
            ag.plan,
            a.id AS activity_id,
            a.start AS start_time, 
            a.end AS end_time,
            atd.name AS activity_type_name, 
            a.jury_lane AS lane,
            a.jury_team AS team,
            a.table_1 AS table_1,
            a.table_1_team AS table_1_team,
            a.table_2 AS table_2,
            a.table_2_team AS table_2_team,
            COALESCE(peb.name, matd.name) AS activity_detail_name, 
            fp.name AS first_program_name,
            rt.name AS room_type_name -- Add room type name
        FROM 
            activity_group ag
        JOIN 
            activity a ON ag.id = a.activity_group
        JOIN 
            m_activity_type_detail atd ON a.activity_type_detail = atd.id
        LEFT JOIN 
            m_activity_type_detail matd ON a.activity_type_detail = matd.id
        LEFT JOIN 
            m_first_program fp ON atd.first_program = fp.id
        LEFT JOIN
            m_room_type rt ON a.room_type = rt.id -- Join with room type table
        LEFT JOIN
            extra_block peb ON a.extra_block = peb.id 
        WHERE 
            ag.plan = ?
        ORDER BY 
            ag.id, a.start ASC
    ";

    $stmt = $g_db->prepare($query);
    $stmt->bind_param('i', $g_plan);
    $stmt->execute();
    $result = $stmt->get_result();

    // Store activities by activity group
    $activities_by_group = [];
    while ($row = $result->fetch_assoc()) {
        $activities_by_group[$row['activity_group_id']][] = $row;
    }

    $stmt->close();

    // Output the HTML
    echo "<h1>Activities for Plan $g_plan</h1>";

    foreach ($activities_by_group as $group_id => $activities) {
        echo "<h2>Activity Group ID: $group_id</h2>";

        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr>
                <th>Activity ID</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>FIRST Program</th>
                <th>Activity Name</th>
                <th>Room Type</th> <!-- Add column for room type -->
                <th>Lane</th>
                <th>Team</th>
                <th>Table 1</th>
                <th>Table 1 Team</th>
                <th>Table 2</th>
                <th>Table 2 Team</th>
              </tr>";

        // Output each activity in the group
        foreach ($activities as $activity) {
            echo "<tr>";
            echo "<td>{$activity['activity_id']}</td>";
            echo "<td>{$activity['start_time']}</td>";
            echo "<td>{$activity['end_time']}</td>";
            echo "<td>{$activity['first_program_name']}</td>"; // FIRST Program name
            echo "<td>{$activity['activity_detail_name']}</td>";
            echo "<td>{$activity['room_type_name']}</td>"; // Room Type name
            echo "<td>{$activity['lane']}</td>";
            echo "<td>{$activity['team']}</td>";
            echo "<td>{$activity['table_1']}</td>";
            echo "<td>{$activity['table_1_team']}</td>";
            echo "<td>{$activity['table_2']}</td>";
            echo "<td>{$activity['table_2_team']}</td>";
            echo "</tr>";
        }

        echo "</table><br>";
    }

} else {
    echo "No plan specified.";
}

echo "</body></html>";
?>
