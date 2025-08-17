<?php
require_once '../generator_db.php';

echo "<html><head><title>Show DB</title><style>
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

// Check if a specific table is requested
$table_name = isset($_GET['table']) ? $_GET['table'] : '';

if ($table_name) {
    // If a specific table is requested, show the table definition and last 500 entries

    // Query to get the table definition
    $query = "DESCRIBE $table_name";
    $result = $g_db->query($query);

    if ($result === false) {
        die('Error: ' . $g_db->error);
    }

    echo "<h1>Table Definition for '$table_name'</h1>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr>
            <th>Field</th>
            <th>Type</th>
            <th>Null</th>
            <th>Key</th>
            <th>Default</th>
            <th>Extra</th>
          </tr>";

    // Prepare the insert form
    $insert_form = "<form method='POST' action='generator_show_db.php?table=$table_name'>";

    // Display the table definition
    $fields = [];
    while ($row = $result->fetch_assoc()) {
        $fields[] = $row['Field'];
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";

        // Add input fields to the insert form (ignore auto_increment fields)
        if (strpos($row['Extra'], 'auto_increment') === false) {
            $insert_form .= "{$row['Field']}: <input type='text' name='{$row['Field']}'><br>";
        }
    }
    echo "</table>";

    // Query to get the 500 entries with the highest ID, ordered in ascending order
    $query = "SELECT * FROM $table_name ORDER BY 1 DESC LIMIT 500";
    $result = $g_db->query($query);

    if ($result === false) {
        die('Error: ' . $g_db->error);
    }

    // Display the last 500 entries
    echo "<h1>Last 500 Entries in '$table_name'</h1>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";

    // Fetch column names
    $columns = array_keys($result->fetch_assoc());
    echo "<tr>";
    foreach ($columns as $column) {
        echo "<th>$column</th>";
    }
    echo "</tr>";

    // Fetch and display the rows (now in ascending order)
    $result->data_seek(0); // Reset result pointer
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $cell) {
            echo "<td>$cell</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    // Complete the insert form
    $insert_form .= "<input type='submit' value='Insert New Entry'></form>";

    // Display the insert form
    echo "<h2>Insert a New Entry</h2>";
    echo $insert_form;

    // Handle the insertion of a new row
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $columns = [];
        $values = [];

        foreach ($fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $columns[] = $field;
                $values[] = "'" . $g_db->real_escape_string($_POST[$field]) . "'";
            }
        }

        if (count($columns) > 0 && count($values) > 0) {
            $insert_query = "INSERT INTO $table_name (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
            if ($g_db->query($insert_query) === true) {
                echo "<p>New entry inserted successfully!</p>";
                // Refresh the page after insertion to show the updated table
                header("Location: generator_show_db.php?table=$table_name");
                exit();
            } else {
                echo "<p>Error inserting new entry: " . $g_db->error . "</p>";
            }
        } else {
            echo "<p>No values entered for insertion!</p>";
        }
    }

    // Add a link to go back to the table list
    echo "<br><a href='generator_show_db.php'>Back to table list</a>";

} else {
    // If no specific table is requested, list all tables with entry counts

    // Query to get all tables in the database
    $query = "SHOW TABLES";
    $result = $g_db->query($query);

    if ($result === false) {
        die('Error: ' . $g_db->error);
    }

    echo "<h1>Database Tables</h1>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr>
            <th>Table Name</th>
            <th>Number of Entries</th>
          </tr>";

    // Loop through each table and get the number of entries
    while ($row = $result->fetch_row()) {
        $table_name = $row[0];

        // Count the number of entries in each table
        $count_query = "SELECT COUNT(*) as total FROM $table_name";
        $count_result = $g_db->query($count_query);
        $count_row = $count_result->fetch_assoc();
        $entry_count = $count_row['total'];

        // Display table name and number of entries, with the table name as a link
        echo "<tr>";
        echo "<td><a href='generator_show_db.php?table=$table_name'>$table_name</a></td>";
        echo "<td>$entry_count</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</body></html>";
?>
