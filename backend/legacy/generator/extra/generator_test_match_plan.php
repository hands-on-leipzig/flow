<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Robot Game Match Plan</title>
    <style>
        table {
            border-collapse: collapse;
            margin: 10px;
        }
        th, td {
            border: 1px solid black;
            padding: 5px;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>Robot Game Match Plan</h1>
    <form method="post">
        <label for="c_teams">Number of Teams (6-30):</label>
        <input type="number" id="c_teams" name="c_teams" min="6" max="30" value="12" required>
        <br>
        <label for="r_tables">Number of Tables:</label>
        <select id="r_tables" name="r_tables" required>
            <option value="2">2</option>
            <option value="4">4</option>
        </select>
        <br>
        <input type="submit" value="Generate Match Plan">
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c_teams = intval($_POST['c_teams']);
        $r_tables = intval($_POST['r_tables']);
        $j_rounds = 3; // Fixed number of rounds
        $j_lanes = 0; // Not used in this context

        $r_match_plan = [];
        $r_match_plan_indicators = [];

        $r_matches_per_round = 0;
        $r_need_volunteer = false;
        $r_asym = false;

        r_match_plan($c_teams, $r_tables, $j_lanes, $j_rounds, $r_match_plan, $r_match_plan_indicators, $r_matches_per_round, $r_need_volunteer, $r_asym);

        echo "<h2>Match Plan</h2>";
        echo "<div style='display: flex;'>";
        for ($round = 1; $round <= $j_rounds; $round++) {
            echo "<table>";
            echo "<tr><th colspan='4'>Round $round</th></tr>";
            echo "<tr><th>Table 1</th><th>Table 2</th><th>Table 3</th><th>Table 4</th></tr>";
            for ($match = 1; $match <= $r_matches_per_round; $match++) {
                echo "<tr>";
                for ($table = 1; $table <= 4; $table++) {
                    $team = '';
                    foreach ($r_match_plan as $match_plan) {
                        if ($match_plan['round'] == $round && $match_plan['match'] == $match) {
                            if ($match_plan['table_1'] == $table) {
                                $team = $match_plan['team_1'];
                            } elseif ($match_plan['table_2'] == $table) {
                                $team = $match_plan['team_2'];
                            }
                            break;
                        }
                    }
                    echo "<td>";
                    if ($team) echo $team;
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";

        echo "<h2>Team Assignments</h2>";
        echo "<table>";
        echo "<tr><th>Team</th><th>Table 1</th><th>Table 2</th><th>Table 3</th><th>Table Count</th><th>Team 1</th><th>Team 2</th><th>Team 3</th><th>Team Count</th><th>Matches Between R1-R2</th><th>Matches Between R2-R3</th></tr>";
        foreach ($r_match_plan_indicators as $indicator) {
            $team = $indicator['team'];
            $unique_tables = $indicator['table_count'];
            $unique_teams_met = $indicator['teams_met'];
            $matches_between_r1_r2 = $indicator['matches_between_r1_r2'];
            $matches_between_r2_r3 = $indicator['matches_between_r2_r3'];
            echo "<tr><td>$team</td>";
            $teams_met = [];
            for ($round = 1; $round <= $j_rounds; $round++) {
                $table = '';
                foreach ($r_match_plan as $match_plan) {
                    if ($match_plan['round'] == $round && ($match_plan['team_1'] == $team || $match_plan['team_2'] == $team)) {
                        $table = ($match_plan['team_1'] == $team) ? $match_plan['table_1'] : $match_plan['table_2'];
                        $teams_met[] = ($match_plan['team_1'] == $team) ? $match_plan['team_2'] : $match_plan['team_1'];
                        break;
                    }
                }
                echo "<td>";
                if ($table) echo $table;
                echo "</td>";
            }
            $table_bg_color = ($r_tables == 2 && $unique_tables < 2) || ($r_tables == 4 && $unique_tables < 3) ? 'lightcoral' : 'lightgreen';
            $teams_bg_color = $unique_teams_met < 3 ? 'lightcoral' : 'lightgreen';
            echo "<td style='background-color: $table_bg_color;'>$unique_tables</td>";
            for ($i = 0; $i < 3; $i++) {
                echo "<td>";
                if (isset($teams_met[$i])) echo $teams_met[$i];
                echo "</td>";
            }
            echo "<td style='background-color: $teams_bg_color;'>$unique_teams_met</td>";
            echo "<td>$matches_between_r1_r2</td>";
            echo "<td>$matches_between_r2_r3</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<h2>Match Plan Array Dump</h2>";
        echo "<pre>";
        print_r($r_match_plan);
        echo "</pre>";

        echo "<h2>Quality Indicators Array Dump</h2>";
        echo "<pre>";
        print_r($r_match_plan_indicators);
        echo "</pre>";
    }

    function r_match_plan($c_teams, $r_tables, $j_lanes, $j_rounds,
                          &$r_match_plan, &$r_match_plan_indicators, &$r_matches_per_round, &$r_need_volunteer, &$r_asym) {
        global $DEBUG;

        $r_matches_per_round = ceil($c_teams / 2);
        $r_need_volunteer = $r_matches_per_round != $c_teams / 2;
        $r_asym = $r_tables == 4 && (($c_teams % 4 == 1) || ($c_teams % 4 == 2));

        if ($DEBUG) {
            echo "<h2>Robot Game Match-Plan $c_teams-$j_lanes-$r_tables</h2>";
            echo "C teams: $c_teams<br>";
            echo "J lanes: $j_lanes<br>";
            echo "R tables: $r_tables<br>";
            echo "J rounds: $j_rounds<br>";
            echo "RG matches per round: $r_matches_per_round<br>";
            echo "RG need volunteer: " . ($r_need_volunteer ? 'Yes' : 'No') . "<br>";
            echo "RG asymmetric: " . ($r_asym ? 'Yes' : 'No') . "<br>";
        }

        $r_match_plan = [];

        for ($round = 1; $round <= $j_rounds; $round++) {
            $teams = range(1, $c_teams);
            shuffle($teams);

            for ($match = 1; $match <= $r_matches_per_round; $match++) {
                $team_1 = array_shift($teams);
                $team_2 = array_shift($teams);

                if ($team_2 === null) {
                    $team_2 = 0;
                }

                if ($r_tables == 4) {
                    $table_1 = ($match % 2 == 1) ? 1 : 3;
                    $table_2 = $table_1 + 1;
                } else {
                    $table_1 = 1;
                    $table_2 = 2;
                }

                $r_match_plan[] = [
                    'round' => $round,
                    'match' => $match,
                    'table_1' => $table_1,
                    'table_2' => $table_2,
                    'team_1' => ($team_1 > $c_teams) ? 0 : $team_1,
                    'team_2' => ($team_2 > $c_teams) ? 0 : $team_2,
                ];
            }
        }
  
        for ($team = 1; $team <= $c_teams; $team++) {
            $tables_assigned = [];
            $teams_met = [];
            $matches_between_r1_r2 = 0;
            $matches_between_r2_r3 = 0;
            $round_1_match = $round_2_match = $round_3_match = null;
            for ($round = 1; $round <= 3; $round++) {
                foreach ($r_match_plan as $match_plan) {
                    if ($match_plan['round'] == $round && ($match_plan['team_1'] == $team || $match_plan['team_2'] == $team)) {
                        $table = ($match_plan['team_1'] == $team) ? $match_plan['table_1'] : $match_plan['table_2'];
                        $tables_assigned[] = $table;
                        $teams_met[] = ($match_plan['team_1'] == $team) ? $match_plan['team_2'] : $match_plan['team_1'];
                        if ($round == 1) $round_1_match = $match_plan['match'];
                        if ($round == 2) $round_2_match = $match_plan['match'];
                        if ($round == 3) $round_3_match = $match_plan['match'];
                        break;
                    }
                }
            }
            if ($round_1_match !== null && $round_2_match !== null) {
                $matches_between_r1_r2 = ($r_matches_per_round - $round_1_match) + ($round_2_match - 1);
            }
            if ($round_2_match !== null && $round_3_match !== null) {
                $matches_between_r2_r3 = ($r_matches_per_round - $round_2_match) + ($round_3_match - 1);
            }
            $unique_tables = count(array_unique($tables_assigned));
            $unique_teams_met = count(array_unique($teams_met));
            $r_match_plan_indicators[] = [
                'team' => $team,
                'table_count' => $unique_tables,
                'teams_met' => $unique_teams_met,
                'matches_between_r1_r2' => $matches_between_r1_r2,
                'matches_between_r2_r3' => $matches_between_r2_r3
            ];
        }
    }
    ?>
</body>
</html>