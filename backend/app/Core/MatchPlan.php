<?php

namespace App\Core;

use Illuminate\Support\Collection;
use App\Support\PlanParameter;
use Illuminate\Support\Facades\DB;
use DateTime;

class MatchPlan
{
    private array $entries = [];
    private ActivityWriter $writer;

    public function __construct(ActivityWriter $writer)
    {
        $this->writer = $writer;
    }

    // Create the robot game match plan regardless of the number of tables and timing

    public function create(): void
    {
        $this->entries = [];

        // Generate rounds 1 to 3 matching the judging round
        // Then build the test round from round 1
        // - preserve the table assignments
        // - shift matches "backwards" to fit judging round 1

        for ($round = 0; $round <= 3; $round++) {

            if ($round == 0) {
                // TR is easy: Teams starting with judging are last in TR
                $team = pp("j_lanes");
            } else {
                switch (pp("j_rounds")) {
                    case 4:
                        if ($round < 3) {
                            $team = pp("j_lanes") * ($round + 1);
                        } else {
                            $team = pp("c_teams");
                        }
                        break;

                    case 5:
                        if ($round < 3) {
                            $team = pp("j_lanes") * ($round + 2);
                        } else {
                            $team = pp("c_teams");
                        }
                        break;

                    case 6:
                        $team = pp("j_lanes") * ($round + 2);
                        break;

                        // not all lanes may be filled in last judging round, 
                        // but that does not matter with six rounds, because robot game is aligned with judging 5
                }

                // If we have an odd number of teams, start with volunteer
                if ($team == pp("c_teams") && pp("r_need_volunteer")) {
                    $team = pp("c_teams") + 1;
                }
            }

            // fill the match-plan for the round starting with the last match, then going backwards
            // Start with just 2 tables. Distribution to 4 tables is done afterwards.

            for ($match = pp("r_matches_per_round"); $match >= 1; $match--) {
                $team_2 = $team;
                $this->getNextTeam($team);
                $team_1 = $team;
                $this->getNextTeam($team);

                $this->entries[] = [
                    'round'   => $round,
                    'match'   => $match,
                    'table_1' => 1,
                    'table_2' => 2,
                    'team_1'  => ($team_1 > pp("c_teams")) ? 0 : $team_1,   // Change volunteer from pp("c_teams")
                    'team_2'  => ($team_2 > pp("c_teams")) ? 0 : $team_2,   // Change volunteer from pp("c_teams")
                ];
            }

            // With four tables move every second line to the other pair.
            if (pp("r_tables") == 4) {
                foreach ($this->entries as &$entry) {
                    if ($entry['match'] % 2 == 0) {
                        // Move table assignments from 1-2 to 3-4
                        $entry['table_1'] = 3;
                        $entry['table_2'] = 4;
                    }
                }
            }
        }

        // Now, ensure that matches in TR are on the same tables as in RG1  
        // This is quality measure Q2

        // Sequence of matches in TR is already correct, but the table assigment must be copied from RG1 to TR

        
        if ( (pp("j_lanes") % 2 === 1) && pp("r_tables") == 4  && pp("j_rounds") == 4 )  {
    
            // Special case where lanes are (1,3,5), 4 tables and 4 judging rounds
            // Q2 not met, but match plan for TR works! 
            // Hits 8 configuations as of Sep 3, 2025
            // TODO

        } else {
        
            for ($match0 = 1; $match0 <= pp("r_matches_per_round"); $match0++) {
                foreach ($this->entries as &$match) {
                    if ($match['round'] === 0 && $match['match'] === $match0) {
                        $team1 = $match['team_1'];
                        $team2 = $match['team_2'];

                        // Search for Team 1 in Round 1
                        $m1 = collect($this->entries)->first(fn($m) =>
                            $m['round'] === 1 && ($m['team_1'] === $team1 || $m['team_2'] === $team1)
                        );
                        if ($m1) {
                            $match['table_1'] = ($m1['team_1'] === $team1) ? $m1['table_1'] : $m1['table_2'];
                        }

                        // Search for Team 2 in Round 1
                        $m2 = collect($this->entries)->first(fn($m) =>
                            $m['round'] === 1 && ($m['team_1'] === $team2 || $m['team_2'] === $team2)
                        );
                        if ($m2) {
                            $match['table_2'] = ($m2['team_1'] === $team2) ? $m2['table_1'] : $m2['table_2'];
                        }

                        break;
                    }
                }
            }
        }

        // Special handling for asymmetric robot games
        if (pp('r_asym')) {

            // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
            //
            // The issue only happens if r_asym is true
            // This means c_teams = 10, 14, 18, 22 or 26 teams (or one team less)

            // Solution is to add an empty match at tables 3+4 after j_lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan
            
            $newList = [];
            foreach ($this->entries as $entry) {

                // Change only TR only 

                if ($entry['round'] === 0 && $entry['match'] > pp("j_lanes")) {
                    $entry['match'] += 1;
                }

                // copy all modified or unmodified entries
                $newList[] = $entry;
            }

            // Insert new match after j_lanes matches
            $newList[] = [
                'round'   => 0,
                'match'   => pp("j_lanes") + 1,
                'table_1' => 3,
                'table_2' => 4,
                'team_1'  => 0,
                'team_2'  => 0,
            ];

            $this->entries = $newList;
        }
    }

    private function getNextTeam(&$team) {

        // Get the next team with lower number
        // When 0 is reached cycle to max number
        // Include volunteer team if needed

        $team--;

        if ($team == 0) {
            if (pp("r_need_volunteer")) {
                $team = pp("c_teams") + 1; // Volunteer team
            } else {
                $team = pp("c_teams");
            }
        }
    } 
    
    private function insertOneMatch(
        \DateTime $startTime,
        int $duration,
        int $table1,
        int $team1,
        int $table2,
        int $team2,
        bool $withRobotCheck
    ): void {

        // Approach: If robot check is needed, add it first and then the match. Otherwise, add the match directly.
        // The time provide to the function is the start time of the match, regardless of robot check.

        // $time is local to this function. $r_time needs to be adjusted by the caller of this function.
        $time = clone $startTime;

        // With robot check, that comes first and the match is delayed accordingly   
        if ($withRobotCheck) {
            $this->writer->insertActivity(
                'r_check',
                $time,
                pp('r_duration_robot_check'),
                null, null,
                $table1, $team1,
                $table2, $team2
            );

            $time->modify('+' . pp('r_duration_robot_check') . ' minutes');
        }

        // Danach das Match
        $this->writer->insertActivity(
            'r_match',
            $time,
            $duration,
            null, null,
            $table1, $team1,
            $table2, $team2
        );
    }



    public function insertOneRound(int $round, DateTime $rTime): DateTime
    {
        // 1) Activity-Group nach Round setzen
        switch ($round) {
            case 0:
                $this->writer->insertActivityGroup('r_round_test');
                break;
            case 1:
                $this->writer->insertActivityGroup('r_round_1');
                break;
            case 2:
                $this->writer->insertActivityGroup('r_round_2');
                break;
            case 3:
                $this->writer->insertActivityGroup('r_round_3');
                break;
        }

        // 2) Matches dieser Runde filtern + sortieren
        $filtered = array_filter($this->entries, fn ($m) => $m['round'] === $round);
        usort($filtered, fn ($a, $b) => $a['match'] <=> $b['match']);

        // 3) Matches schreiben
        foreach ($filtered as $match) {
            // Dauer bestimmen (TR vs RG)
            $duration = ($round === 0)
                ? pp("r_duration_test_match")
                : pp("r_duration_match");

            // exotischer Fall: leeres TR-Match überspringen
            if (!($match['team_1'] === 0 && $match['team_2'] === 0)) {
                // Achtung: insertOneMatch verändert rTime NICHT (Legacy-Semantik)
                $this->insertOneMatch(
                    $rTime,
                    $duration,
                    $match['table_1'],
                    $match['team_1'],
                    $match['table_2'],
                    $match['team_2'],
                    (bool) pp("r_robot_check")
                );
            }

            // 4) Zeitachse fortschreiben (abhängig von #Tische & Round)
            if (pp("r_tables") === 2) {
                // 2 Tische: Nächstes Match wartet bis dieses zu Ende ist
                $rTime->modify("+{$duration} minutes");
            } else {
                // 4 Tische
                if ($round === 0) {
                    // TR: Startzeiten alternieren zwischen next_start und (match - next_start)
                    if (($match['match']) % 2 === 1) {
                        $rTime->modify('+' . pp("r_duration_next_start") . ' minutes');
                    } else {
                        $delta = $duration - pp("r_duration_next_start");
                        $rTime->modify("+{$delta} minutes");
                    }
                } else {
                    // RG1–3: Overlap — nächster Start alle r_duration_next_start
                    $rTime->modify('+' . pp("r_duration_next_start") . ' minutes');
                }
            }
        }

        // 5) Robot-Check addiert am Rundenende zusätzliche Zeit
        if ((bool) pp("r_robot_check")) {
            $rTime->modify('+' . pp("r_duration_robot_check") . ' minutes');
        }

        // 6) Fix für 4 Tische: wenn letztes Match vorbei ist, Gesamtdauer korrigieren
        if (pp("r_tables") === 4) {
            $delta = pp("r_duration_match") - pp("r_duration_next_start");
            $rTime->modify("+{$delta} minutes");
        }

        // 7) Inserted Blocks / Pausen für NÄCHSTE Runde
        switch ($round) {
            case 0:
                // nach TR
                $this->writer->insertPoint('rg_tr', pp("r_duration_break"), $rTime);
                break;

            case 1:
                // nach RG1
                if (pp("e_mode") == ID_E_MORNING || pp("e_mode") == ID_E_AFTERNOON) {
                    // Explore-Integration (Legacy-Funktion beibehalten)
                    e_integrated();
                } else {
                    // unabhängige Lunch-Pause (außer harter Break)
                    if (pp('c_duration_lunch_break') === 0) {
                        $this->writer->insertPoint('rg_1', pp("r_duration_lunch"), $rTime);
                    }
                }
                break;

            case 2:
                // nach RG2
                $this->writer->insertPoint('rg_2', pp("r_duration_break"), $rTime);
                break;

            case 3:
                // nach RG3
                $this->writer->insertPoint('rg_3', pp("r_duration_results"), $rTime);
                break;
        }

        return $rTime;
    }
    

}