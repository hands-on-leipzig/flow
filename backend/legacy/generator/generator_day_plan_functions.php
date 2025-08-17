<?php

require_once 'generator_db.php';

function g_show_day_plan($plan)
{
    global $g_db;
    global $g_plan;
    
    $g_plan = $plan;

    // Initialize the array to store activities
    $activities = [];

    // Initialize an empty array to store processed activity IDs
    $processedIds = [];

    // Initialize an empty array to store columns needed
    $columnsNeeded = [];

    // Query to get the plan name
    $plan_query = "SELECT name FROM plan WHERE id = ?";
    $plan_stmt = $g_db->prepare($plan_query);
    $plan_stmt->bind_param('i', $g_plan);
    $plan_stmt->execute();
    $plan_result = $plan_stmt->get_result();
    $p_name = $plan_result->fetch_assoc()['name'];
    $plan_stmt->close();

    // Query to get activities for the given plan
    $query = "
        SELECT 
            a.id AS activity_id,
            a.start AS start_time, 
            a.end AS end_time, 
            COALESCE(peb.name, atd.name_preview) AS activity_name, 
            atd.id AS activity_type_detail_id,
            fp.name AS program_name,
            a.jury_lane AS lane,
            a.jury_team AS team,
            a.table_1 AS table_1,
            a.table_1_team AS table_1_team,
            a.table_2 AS table_2,
            a.table_2_team AS table_2_team
        FROM 
            activity a
        JOIN 
            activity_group ag ON a.activity_group = ag.id
        JOIN 
            m_activity_type_detail atd ON a.activity_type_detail = atd.id
        LEFT JOIN 
            m_first_program fp ON atd.first_program = fp.id
        LEFT JOIN 
            extra_block peb ON a.extra_block = peb.id
        WHERE 
            ag.plan = ? AND atd.id != " . ID_ATD_FREE . " AND atd.id != " . ID_ATD_E_FREE . " AND atd.id != " . ID_ATD_C_FREE . "
        ORDER BY 
            a.start ASC
    ";

    $stmt = $g_db->prepare($query);
    $stmt->bind_param('i', $g_plan);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }

    $stmt->close();

    // Determine the start and end time of the day plan
    $start_time = new DateTime($activities[0]['start_time']);
    $end_time = new DateTime(end($activities)['end_time']);

    // Determine the start and end time per day
    $daily_times = [];
    foreach ($activities as $activity) {
        $activity_date = (new DateTime($activity['start_time']))->format('Y-m-d');
        $activity_start = new DateTime($activity['start_time']);
        $activity_end = new DateTime($activity['end_time']);

        if (!isset($daily_times[$activity_date])) {
            $daily_times[$activity_date] = [
                'start_time' => $activity_start,
                'end_time' => $activity_end
            ];
        } else {
            if ($activity_start < $daily_times[$activity_date]['start_time']) {
                $daily_times[$activity_date]['start_time'] = $activity_start;
            }
            if ($activity_end > $daily_times[$activity_date]['end_time']) {
                $daily_times[$activity_date]['end_time'] = $activity_end;
            }
        }

    }

    // Initialize an empty table for the day plan
    $day_plan_table = [];

    // Fill the time slots in 5-minute increments for each day
    foreach ($daily_times as $date => $times) {
        for ($time = clone $times['start_time']; $time <= $times['end_time']; $time->modify('+5 minutes')) {
            $columns = [
                'Zeit' => ['text' => $time->format('d.m. H:i'), 'row_span' => 1],
                'Ch Be' => ['text' => '', 'row_span' => 1],
                'Ch Te' => ['text' => '', 'row_span' => 1],
                'Ch Ju' => ['text' => '', 'row_span' => 1],
                'Ch J1' => ['text' => '', 'row_span' => 1],
                'Ch J2' => ['text' => '', 'row_span' => 1],
                'Ch J3' => ['text' => '', 'row_span' => 1],
                'Ch J4' => ['text' => '', 'row_span' => 1],
                'Ch J5' => ['text' => '', 'row_span' => 1],
                'Ch J6' => ['text' => '', 'row_span' => 1],
                'RG SR' => ['text' => '', 'row_span' => 1],
                'RG C1' => ['text' => '', 'row_span' => 1],
                'RG C2' => ['text' => '', 'row_span' => 1],
                'RG T1' => ['text' => '', 'row_span' => 1],
                'RG T2' => ['text' => '', 'row_span' => 1],
                'RG C3' => ['text' => '', 'row_span' => 1],
                'RG C4' => ['text' => '', 'row_span' => 1],
                'RG T3' => ['text' => '', 'row_span' => 1],
                'RG T4' => ['text' => '', 'row_span' => 1],
                'Ex Be' => ['text' => '', 'row_span' => 1],
                'Ex Te' => ['text' => '', 'row_span' => 1],
                'Ex Gu' => ['text' => '', 'row_span' => 1],
                'Ex G1' => ['text' => '', 'row_span' => 1],
                'Ex G2' => ['text' => '', 'row_span' => 1],
                'Ex G3' => ['text' => '', 'row_span' => 1],
                'Ex G4' => ['text' => '', 'row_span' => 1],
                'Ex G5' => ['text' => '', 'row_span' => 1],
                'Ex G6' => ['text' => '', 'row_span' => 1],
                'LC' => ['text' => '', 'row_span' => 1],
                'LC1' => ['text' => '', 'row_span' => 1],
                'LC2' => ['text' => '', 'row_span' => 1],
                'LC3' => ['text' => '', 'row_span' => 1],
                'LC4' => ['text' => '', 'row_span' => 1],
                'LC5' => ['text' => '', 'row_span' => 1],
                'LC6' => ['text' => '', 'row_span' => 1]
            ];

            // Populate the columns with activities
            foreach ($activities as $activity) {
                $activity_start = new DateTime($activity['start_time']);
                $activity_end = new DateTime($activity['end_time']);

                // Add a space after - to support line breaks in table cells
                $activity['activity_name'] = preg_replace('/-/', '- ', $activity['activity_name']);
                // Add a space after every x characters in a word longer than x characters
                // $activity['activity_name'] = preg_replace('/(\S{5})(?=\S)/', '$1 ', $activity['activity_name']);

                // must be in the current 5 minute interval and not processed before
                if ($time >= $activity_start && $time < $activity_end) {

                    // Calculate the difference in minutes
                    $interval = $activity_end->diff($activity_start);
                    $row_span = (($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i) / 5;

                    // Handle Public activities including inserted blocks

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_E_OPENING, ID_ATD_E_AWARDS, ID_ATD_E_LUNCH_VISITOR, ID_ATD_E_INSERTED])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ex Be']);
                        } else {
                            $columns['Ex Be']['row_span'] = $row_span;
                            $columns['Ex Be']['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_C_OPENING, ID_ATD_C_AWARDS, ID_ATD_C_PRESENTATIONS, ID_ATD_C_LUNCH_VISITOR, ID_ATD_C_INSERTED, ID_ATD_C_OPENING_DAY_1, ID_ATD_C_OPENING_DAY_3])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ch Be']);
                        } else {
                            $columns['Ch Be']['row_span'] = $row_span;
                            $columns['Ch Be']['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_OPENING, ID_ATD_AWARDS, ID_ATD_INSERTED])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ch Be']);
                            unset($columns['Ex Be']);
                        } else {
                            $columns['Ch Be']['row_span'] = $row_span;
                            $columns['Ch Be']['text'] = $activity['activity_name'];
                            $columns['Ex Be']['row_span'] = $row_span;
                            $columns['Ex Be']['text'] = $activity['activity_name'];
                        }
                    }

                    // Handle Judging and Lane activities

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_C_JUDGE_BRIEFING, ID_ATD_C_DELIBERATIONS, ID_ATD_C_LUNCH_JUDGE])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ch Ju']);
                        } else {
                            $columns['Ch Ju' . $activity['lane']]['row_span'] = $row_span;
                            $columns['Ch Ju' . $activity['lane']]['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_C_WITH_TEAM, ID_ATD_C_SCORING])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ch J' . $activity['lane']]);
                        } else {
                            $columns['Ch J' . $activity['lane']]['row_span'] = $row_span;
                            $columns['Ch J' . $activity['lane']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['team'], 2, '0', STR_PAD_LEFT);
                        
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_E_JUDGE_BRIEFING, ID_ATD_E_DELIBERATIONS, ID_ATD_E_LUNCH_JUDGE])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ex Gu']);
                        } else {
                            $columns['Ex Gu']['row_span'] = $row_span;
                            $columns['Ex Gu']['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_E_WITH_TEAM, ID_ATD_E_SCORING])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ex G' . $activity['lane']]);
                        } else {
                            $columns['Ex G' . $activity['lane']]['row_span'] = $row_span;
                            $columns['Ex G' . $activity['lane']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['team'], 2, '0', STR_PAD_LEFT);
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_LC_JUDGE_BRIEFING, ID_ATD_LC_DELIBERATIONS])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['LC']);
                        } else {
                            $columns['LC']['row_span'] = $row_span;
                            $columns['LC']['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_LC_WITH_TEAM, ID_ATD_LC_SCORING])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['LC' . $activity['lane']]);
                        } else {
                            $columns['LC' . $activity['lane']]['row_span'] = $row_span;
                            $columns['LC' . $activity['lane']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['team'], 2, '0', STR_PAD_LEFT);
                        }
                    }

                    
                    // Handle matches and robot check. These have tables assigned

                    if (!empty($activity['table_1'])) {

                        if ($activity['activity_type_detail_id'] == ID_ATD_R_MATCH) {
                            // Match
                            if (in_array($activity['activity_id'], $processedIds)) {
                                unset($columns['RG T' . $activity['table_1']]);
                            } else {
                                $columns['RG T' . $activity['table_1']]['row_span'] = $row_span;

                                if ($activity['table_1_team'] == 0) {
                                    $columns['RG T' . $activity['table_1']]['text'] = $activity['activity_name'];
                                } else {
                                    $columns['RG T' . $activity['table_1']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['table_1_team'], 2, '0', STR_PAD_LEFT);
                                }
                            }
                        } else {
                            // Robot check
                            if (in_array($activity['activity_id'], $processedIds)) {
                                unset($columns['RG C' . $activity['table_1']]);
                            } else {
                                $columns['RG C' . $activity['table_1']]['row_span'] = $row_span;

                                if ($activity['table_1_team'] == 0) {
                                    $columns['RG C' . $activity['table_1']]['text'] = $activity['activity_name'];
                                } else {
                                    $columns['RG C' . $activity['table_1']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['table_1_team'], 2, '0', STR_PAD_LEFT);
                                }
                            }
                        }                
                    }

                    if (!empty($activity['table_2'])) {

                        if ($activity['activity_type_detail_id'] == ID_ATD_R_MATCH) {
                            // Match
                            if (in_array($activity['activity_id'], $processedIds)) {
                                unset($columns['RG T' . $activity['table_2']]);
                            } else {
                                $columns['RG T' . $activity['table_2']]['row_span'] = $row_span;

                                if ($activity['table_2_team'] == 0) {
                                    $columns['RG T' . $activity['table_2']]['text'] = $activity['activity_name'];
                                } else {                                
                                    $columns['RG T' . $activity['table_2']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['table_2_team'], 2, '0', STR_PAD_LEFT);
                                }
                            }
                        } else {
                            // Robot check
                            if (in_array($activity['activity_id'], $processedIds)) {
                                unset($columns['RG C' . $activity['table_2']]);
                            } else {
                                $columns['RG C' . $activity['table_2']]['row_span'] = $row_span;

                                if ($activity['table_2_team'] == 0) {
                                    $columns['RG C' . $activity['table_2']]['text'] = $activity['activity_name'];
                                } else {            
                                    $columns['RG C' . $activity['table_2']]['text'] = $activity['activity_name'] . " T" . str_pad($activity['table_2_team'], 2, '0', STR_PAD_LEFT);
                                }
                            }
                        }
                    }


                    // Handle special referee activities 

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_R_REFEREE_BRIEFING, ID_ATD_R_LUNCH_REFEREE, ID_ATD_R_REFEREE_DEBRIEFING])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['RG SR']);
                        } else {
                            $columns['RG SR']['row_span'] = $row_span;
                            $columns['RG SR']['text'] = $activity['activity_name'];
                        }
                    }

                    // Handle special Team activities 

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_C_COACH_BRIEFING, ID_ATD_C_LUNCH_TEAM])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ch Te']);
                        } else {
                            $columns['Ch Te']['row_span'] = $row_span;
                            $columns['Ch Te']['text'] = $activity['activity_name'];
                        }
                    }

                    if (in_array($activity['activity_type_detail_id'], [ID_ATD_E_COACH_BRIEFING, ID_ATD_E_LUNCH_TEAM])) {

                        if (in_array($activity['activity_id'], $processedIds)) {
                            unset($columns['Ex Te']);
                        } else {
                            $columns['Ex Te']['row_span'] = $row_span;
                            $columns['Ex Te']['text'] = $activity['activity_name'];
                        }
                    }

                    // Note the id as processed
                    $processedIds[] = $activity['activity_id'];
                }
            }

            $day_plan_table[] = $columns;
        }

        // Add an empty row with black cell fill to separate days
        $empty_row = [];
        foreach ($columns as $key => $value) {
            $empty_row[$key] = ['text' => '', 'row_span' => 1];
        }
        $day_plan_table[] = $empty_row;
    }

    // Generate HTML output for the day plan table
    echo "<h1>Überblick für '$p_name'</h1>";
    echo "<p>Freie Blöcke werden hier nicht angezeigt, weil sie den Ablauf nicht beeinflussen.</p>";

    $columns = [
        "Zeit" => "white",
        "Ex Be" => "explore",
        "Ex Te" => "explore",
        "Ex Gu" => "explore",
        "Ex G1" => "explore",
        "Ex G2" => "explore",
        "Ex G3" => "explore",
        "Ex G4" => "explore",
        "Ex G5" => "explore",
        "Ex G6" => "explore",
        "Ch Be" => "challenge",
        "Ch Te" => "challenge",
        "Ch Ju" => "challenge",
        "Ch J1" => "challenge",
        "Ch J2" => "challenge",
        "Ch J3" => "challenge",
        "Ch J4" => "challenge",
        "Ch J5" => "challenge",
        "Ch J6" => "challenge",
        "RG SR" => "challenge",
        "RG C1" => "challenge",
        "RG C2" => "challenge",
        "RG T1" => "challenge",
        "RG T2" => "challenge",
        "RG C3" => "challenge",
        "RG C4" => "challenge",
        "RG T3" => "challenge",
        "RG T4" => "challenge",
        "LC" => "challenge",
        "LC1" => "challenge",
        "LC2" => "challenge",
        "LC3" => "challenge",
        "LC4" => "challenge",
        "LC5" => "challenge",
        "LC6" => "challenge"
    ];

    $empty_cols = array_flip(array_keys($columns));

    foreach ($day_plan_table as $row) {
        foreach ($row as $name => $col) {
            if ($col['text'] != "") {
                unset($empty_cols[$name]);
            }
        }
    }

    echo "<div style='display: grid; gap: .1em; grid-template-columns: repeat(" . (count($columns) - count($empty_cols)) . ", 1fr);' >";

    $c = 1;
    foreach ($columns as $name => $color) {
        if (isset($empty_cols[$name])) continue;
        echo "<span style='grid-row: 1/2; grid-column: " . $c . "/" . ($c + 1) . "; background: white;'>" . $name . "</span>";
        $c++;
    }

    $r = 2;
    foreach ($day_plan_table as $row) {
        $c = 1;
        foreach ($columns as $column => $color) {
            if (isset($empty_cols[$column])) continue;
            if (!isset($row[$column])) {
                $c++;
                continue;
            }

            $grid_row = "grid-row: " . $r . "/" . ($row[$column]['row_span'] + $r);
            $grid_col = "grid-column: " . $c . "/" . ($c + 1);
            if ($row[$column]['text'] == "" || preg_match("/[0-9]{2}:[0-9]{2}/", $row[$column]['text'])) {
                $bg = $r % 2 == 0 ? "#eee" : "#aaa";
            } else {
                switch ($color) {
                    default:
                        $bg = "white";
                        break;
                }
            }
            if ($row[$column]['text'] == "" && $column == "Zeit") {
                $bg = "black";
            }
            echo "<span style='" . $grid_row . "; " . $grid_col . "; background: " . $bg . "; padding: .3em;'>" . $row[$column]['text'] . "</span>";
            $c++;
        }
        $r++;
    }

    echo "</div>";

    echo "<p>Datenbank-ID $g_plan</p>";

}

function g_show_team_plan($plan)
{
    global $g_db;
    global $g_plan;
    
    $g_plan = $plan;

    // Fetch the number of teams
    $e_teams = gp('e_teams');
    $c_teams = gp('c_teams');

    echo $e_teams, $c_teams;

    // Initialize the array to store activities
    $activities = [];

    // Query to get the plan name
    $plan_query = "SELECT name FROM plan WHERE id = ?";
    $plan_stmt = $g_db->prepare($plan_query);
    $plan_stmt->bind_param('i', $g_plan);
    $plan_stmt->execute();
    $plan_result = $plan_stmt->get_result();
    $p_name = $plan_result->fetch_assoc()['name'];
    $plan_stmt->close();

    // Query to get activities for the given plan
    $query = "
        SELECT 
            a.id AS activity_id,
            a.start AS start_time, 
            a.end AS end_time, 
            atd.name AS activity_name,
            atd.id AS activity_type_detail_id,
            a.jury_team AS team
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
    $stmt->bind_param('i', $g_plan);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }

    $stmt->close();

    // Determine the start and end time of the day plan
    $start_time = new DateTime($activities[0]['start_time']);
    $end_time = new DateTime(end($activities)['end_time']);

    // Initialize the table columns
    $columns = ["Zeit"];
    for ($i = 1; $i <= $e_teams; $i++) {
        $columns[] = "Ex T" . str_pad($i, 2, '0', STR_PAD_LEFT);
    }
    for ($i = 1; $i <= $c_teams; $i++) {
        $columns[] = "Ch T" . str_pad($i, 2, '0', STR_PAD_LEFT);
    }

    // Create the time slots in 5-minute increments
    $time_slots = [];
    for ($time = clone $start_time; $time <= $end_time; $time->modify('+5 minutes')) {
        $time_slots[] = ["Zeit" => $time->format('d.m. H:i')];
    }

    // Assign activities to their respective time slots and columns
    foreach ($activities as $activity) {
        $activity_start = new DateTime($activity['start_time']);
        $activity_end = new DateTime($activity['end_time']);
        $activity_name = $activity['activity_name'];
        $team_column = ($activity['team'] <= $e_teams) 
            ? "Ex T" . str_pad($activity['team'], 2, '0', STR_PAD_LEFT)
            : "Ch T" . str_pad($activity['team'] - $e_teams, 2, '0', STR_PAD_LEFT);

        foreach ($time_slots as &$slot) {
            $slot_time = DateTime::createFromFormat('d.m. H:i', $slot['Zeit']);
            if ($slot_time >= $activity_start && $slot_time < $activity_end) {
                $slot[$team_column] = $activity_name;
            }
        }
    }

    // Generate the HTML grid
    echo "<h1>Überblick für Plan '$p_name'</h1>";
    echo "<div style='display: grid; gap: .1em; grid-template-columns: repeat(" . count($columns) . ", 1fr);'>";

    // Add column headers
    foreach ($columns as $column) {
        echo "<span style='font-weight: bold; background: #ddd;'>$column</span>";
    }

    // Add rows
    $row_index = 0;
    foreach ($time_slots as $slot) {
        $row_bg = ($row_index % 2 == 0) ? "#eee" : "#fff";
        foreach ($columns as $column) {
            $text = $slot[$column] ?? '';
            $bg = $column === "Zeit" ? $row_bg : ($text ? "#cfc" : $row_bg);
            echo "<span style='background: $bg; padding: .3em;'>$text</span>";
        }
        $row_index++;
    }

    echo "</div>";
}



function g_list_teams_activities($plan)
{
    global $g_db;
    global $g_plan;
    
    $g_plan = $plan;

    // Fetch the number of teams
    $e_teams = gp('e_teams');
    $c_teams = gp('c_teams');

    // Initialize arrays for team activities
    $team_activities = [];
    for ($i = 1; $i <= $e_teams; $i++) {
        $team_activities["Ex T" . str_pad($i, 2, '0', STR_PAD_LEFT)] = [];
    }
    for ($i = 1; $i <= $c_teams; $i++) {
        $team_activities["Ch T" . str_pad($i, 2, '0', STR_PAD_LEFT)] = [];
    }

    // Query to get activities for the given plan
    $query = "
        SELECT 
            a.start AS start_time, 
            a.end AS end_time, 
            atd.name AS activity_name,
            a.jury_team AS jury_team,
            a.table_1_team AS table_1_team,
            a.table_2_team AS table_2_team,
            atd.id AS activity_type_detail_id,
            atd.first_program AS first_program
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
    $stmt->bind_param('i', $g_plan);
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

            if ($row['first_program'] == ID_FP_EXPLORE) {
                $team_column = "Ex T" . str_pad($row['jury_team'], 2, '0', STR_PAD_LEFT);
            } else {
                $team_column = "Ch T" . str_pad($row['jury_team'], 2, '0', STR_PAD_LEFT);
            }

            $team_activities[$team_column][] = [
                'type' => $row['activity_type_detail_id'],
                'entry' => $activity_entry,
                'start' => $start_time,
                'end' => $end_time
            ];
        }

        // Assign activity to table_1_team
        if ($row['table_1_team'] > 0) {
            $team_column = "Ch T" . str_pad($row['table_1_team'], 2, '0', STR_PAD_LEFT);
            $team_activities[$team_column][] = [
                'type' => $row['activity_type_detail_id'],
                'entry' => $activity_entry,
                'start' => $start_time,
                'end' => $end_time
            ];
        }

        // Assign activity to table_2_team
        if ($row['table_2_team'] > 0) {
            $team_column = "Ch T" . str_pad($row['table_2_team'], 2, '0', STR_PAD_LEFT);
            $team_activities[$team_column][] = [
                'type' => $row['activity_type_detail_id'],
                'entry' => $activity_entry,
                'start' => $start_time,
                'end' => $end_time
            ];
        }
    }

    $stmt->close();

    // Generate the HTML output
    echo "<h1>Team Activities for Plan ID $g_plan</h1>";

    echo "<h2>Challenge Teams</h2>";
    foreach ($team_activities as $team => $activities) {
        if (strpos($team, 'Ch T') === 0) {
            echo "<h3>$team</h3>";
            if (empty($activities)) {
                echo "<p>No activities assigned.</p>";
            } else {
                usort($activities, function ($a, $b) {
                    return $a['start'] <=> $b['start'];
                });

                echo "<ul>";
                $previous_end = null;
                foreach ($activities as $activity) {
                    $entry = $activity['entry'];

                    // Show gap, but only if something happend before and not after Robot Check
                    if ($previous_end && $previous_type <> ID_ATD_R_CHECK) {
                        $gap_minutes = $previous_end->diff($activity['start']);
                        $gap_total_minutes = ($gap_minutes->h * 60) + $gap_minutes->i;
                        if ($gap_total_minutes < 15) {
                            echo "<li><span style='color:red; font-weight: bold;'>$gap_total_minutes Min.</span></li>";
                        } else {    
                            echo "<li>$gap_total_minutes Min.</li>";
                        }
                    }

                    echo "<li>$entry</li>";

                    $previous_end = $activity['end'];
                    $previous_type = $activity['type'];
                }
                echo "</ul>";
            }
        }
    }

    echo "<h2>Explore Teams</h2>";
    foreach ($team_activities as $team => $activities) {
        if (strpos($team, 'Ex T') === 0) {
            echo "<h3>$team</h3>";
            if (empty($activities)) {
                echo "<p>No activities assigned.</p>";
            } else {
                usort($activities, function ($a, $b) {
                    return $a['start'] <=> $b['start'];
                });

                echo "<ul>";
                $previous_end = null;
                foreach ($activities as $activity) {
                    $entry = $activity['entry'];

                    if ($previous_end) {
                        $gap_minutes = $previous_end->diff($activity['start']);
                        $gap_total_minutes = ($gap_minutes->h * 60) + $gap_minutes->i;
                        echo "<li>$gap_total_minutes Min.</li>";
                    }

                    echo "<li>$entry</li>";

                    $previous_end = $activity['end'];
                }
                echo "</ul>";
            }
        }

    
    }

}


?>


