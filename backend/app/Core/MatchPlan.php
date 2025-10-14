<?php

namespace App\Core;
use App\Core\TimeCursor;

use Illuminate\Support\Collection;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use Illuminate\Support\Facades\DB;
use App\Models\MatchEntry;
use DateTime;

class MatchPlan
{
    use UsesPlanParameter;

    private ActivityWriter $writer;

    private array $entries = [];

    public function __construct(ActivityWriter $writer, PlanParameter $params)
    {
        $this->writer = $writer;
        $this->params = $params;  // Pflicht für Trait
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
                $team = $this->pp("j_lanes");
            } else {
                switch ($this->pp("j_rounds")) {
                    case 4:
                        if ($round < 3) {
                            $team = $this->pp("j_lanes") * ($round + 1);
                        } else {
                            $team = $this->pp("c_teams");
                        }
                        break;

                    case 5:
                        if ($round < 3) {
                            $team = $this->pp("j_lanes") * ($round + 2);
                        } else {
                            $team = $this->pp("c_teams");
                        }
                        break;

                    case 6:
                        $team = $this->pp("j_lanes") * ($round + 2);
                        break;

                        // not all lanes may be filled in last judging round, 
                        // but that does not matter with six rounds, because robot game is aligned with judging 5
                }

                // If we have an odd number of teams, start with volunteer
                if ($team == $this->pp("c_teams") && $this->pp("r_need_volunteer")) {
                    $team = $this->pp("c_teams") + 1;
                }
            }

            // fill the match-plan for the round starting with the last match, then going backwards
            // Start with just 2 tables. Distribution to 4 tables is done afterwards.

            for ($match = $this->pp("r_matches_per_round"); $match >= 1; $match--) {
                $team_2 = $team;
                $this->getNextTeam($team);
                $team_1 = $team;
                $this->getNextTeam($team);

                $this->entries[] = [
                    'round'   => $round,
                    'match'   => $match,
                    'table_1' => 1,
                    'table_2' => 2,
                    'team_1'  => ($team_1 > $this->pp("c_teams")) ? 0 : $team_1,   // Change volunteer from $this->pp("c_teams")
                    'team_2'  => ($team_2 > $this->pp("c_teams")) ? 0 : $team_2,   // Change volunteer from $this->pp("c_teams")
                ];
            }

            // With four tables move every second line to the other pair.
            if ($this->pp("r_tables") == 4) {
                foreach ($this->entries as &$entry) {
                    if ($entry['match'] % 2 == 0) {
                        // Move table assignments from 1-2 to 3-4
                        $entry['table_1'] = 3;
                        $entry['table_2'] = 4;
                    }
                }
                unset($entry);
            }
        }

        // Now, ensure that matches in TR are on the same tables as in RG1  
        // This is quality measure Q2

        // Sequence of matches in TR is already correct, but the table assigment must be copied from RG1 to TR

        
        if ( ($this->pp("j_lanes") % 2 === 1) && $this->pp("r_tables") == 4  && $this->pp("j_rounds") == 4 )  {
    
            // Special case where lanes are (1,3,5), 4 tables and 4 judging rounds
            // Q2 not met, but match plan for TR works! 
            // Hits 8 configuations as of Sep 3, 2025
            // TODO

        } else {
        
            for ($match0 = 1; $match0 <= $this->pp("r_matches_per_round"); $match0++) {
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
            unset($match);
        }

        // Special handling for asymmetric robot games
        if ($this->pp('r_asym')) {

            // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
            //
            // The issue only happens if r_asym is true
            // This means c_teams = 10, 14, 18, 22 or 26 teams (or one team less)

            // Solution is to add an empty match at tables 3+4 after j_lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan
            
            $newList = [];
            foreach ($this->entries as $entry) {

                // Change only TR only 

                if ($entry['round'] === 0 && $entry['match'] > $this->pp("j_lanes")) {
                    $entry['match'] += 1;
                }

                // copy all modified or unmodified entries
                $newList[] = $entry;
            }

            // Insert new match after j_lanes matches
            $newList[] = [
                'round'   => 0,
                'match'   => $this->pp("j_lanes") + 1,
                'table_1' => 3,
                'table_2' => 4,
                'team_1'  => 0,
                'team_2'  => 0,
            ];

            $this->entries = $newList;
        }

        // Save match entries to database
        $this->saveMatchEntries();
    }


    private function saveMatchEntries(): void
    {
        $planId = $this->pp('g_plan');
        
        // Clear existing match entries for this plan
        MatchEntry::where('plan', $planId)->delete();

        // Insert new match entries
        foreach ($this->entries as $entry) {
            MatchEntry::create([
                'plan' => $planId,
                'round' => $entry['round'],
                'match_no' => $entry['match'],
                'table_1' => $entry['table_1'],
                'table_2' => $entry['table_2'],
                'table_1_team' => $entry['team_1'],
                'table_2_team' => $entry['team_2'],
            ]);
        }
    }

    private function getNextTeam(&$team) {

        // Get the next team with lower number
        // When 0 is reached cycle to max number
        // Include volunteer team if needed

        $team--;

        if ($team == 0) {
            if ($this->pp("r_need_volunteer")) {
                $team = $this->pp("c_teams") + 1; // Volunteer team
            } else {
                $team = $this->pp("c_teams");
            }
        }
    } 
    
    private function insertOneMatch(
        TimeCursor $rTime,
        int $duration,
        int $table1,
        int $team1,
        int $table2,
        int $team2,
        bool $robotCheck
    ): void {

        // Approach: If robot check is needed, add it first and then the match. Otherwise, add the match directly.
        // The time provide to the function is the start time of the match, regardless of robot check.

        // $time is local to this function. $r_time needs to be adjusted by the caller of this function.


        // Clone, damit wir die Startzeit für Robot-Check/Match korrekt festhalten
        $time = $rTime->copy();

        // Mit Robot-Check → zuerst Check eintragen, dann Match starten
        if ($robotCheck) {
            $this->writer->insertActivity(
                'r_check',
                $time,
                $this->pp('r_duration_robot_check'),
                null,
                null,
                $table1,
                $team1,
                $table2,
                $team2
            );

            // Zeit weiterdrehen
            $time->addMinutes($this->pp('r_duration_robot_check'));
        }

        // Match eintragen
        $this->writer->insertActivity(
            'r_match',
            $time,
            $duration,
            null,
            null,
            $table1,
            $team1,
            $table2,
            $team2
        );
    }    


    public function insertOneRound(int $round, TimeCursor $rTime)
    {
        // 1) Activity-Group nach Round setzen
        switch ($round) {
            case 0:
                $this->writer->insertActivityGroup('r_test_round');
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
                ? $this->pp("r_duration_test_match")
                : $this->pp("r_duration_match");

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
                    $this->pp("r_robot_check")
                );
            }

            // 4) Zeitachse fortschreiben (abhängig von #Tische & Round)
            if ($this->pp("r_tables") === 2) {
                // 2 Tische: Nächstes Match wartet bis dieses zu Ende ist
                $rTime->addMinutes($duration);
            } else {
                // 4 Tische
                if ($round === 0) {
                    // TR: Startzeiten alternieren zwischen next_start und (match - next_start)
                    if (($match['match']) % 2 === 1) {
                        $rTime->addMinutes($this->pp("r_duration_next_start"));
                    } else {
                        $delta = $duration - $this->pp("r_duration_next_start");
                        $rTime->addMinutes($delta);
                    }
                } else {
                    // RG1–3: Overlap — nächster Start alle r_duration_next_start
                    $rTime->addMinutes($this->pp("r_duration_next_start"));
                }
            }
        }

        // 5) Robot-Check addiert am Rundenende zusätzliche Zeit
        if ($this->pp("r_robot_check")) {
            $rTime->addMinutes($this->pp("r_duration_robot_check"));
        }

        // 6) Fix für 4 Tische: wenn letztes Match vorbei ist, Gesamtdauer korrigieren
        if ($this->pp("r_tables") === 4) {
            $delta = $this->pp("r_duration_match") - $this->pp("r_duration_next_start");
            $rTime->addMinutes($delta);
        }

        // 7) Inserted Blocks / Pausen für NÄCHSTE Runde
        switch ($round) {
            case 0:
                $this->writer->insertPoint('rg_tr', $this->pp("r_duration_break"), $rTime);
                break;

            case 1:
                if ($this->pp("e_mode") == ID_E_MORNING || $this->pp("e_mode") == ID_E_AFTERNOON) {
                    e_integrated(); // Legacy-Funktion bleibt so
                } else {
                    if ($this->pp('c_duration_lunch_break') === 0) {
                        $this->writer->insertPoint('rg_1', $this->pp("r_duration_lunch"), $rTime);
                    }
                }
                break;

            case 2:
                $this->writer->insertPoint('rg_2', $this->pp("r_duration_break"), $rTime);
                break;

            case 3:
                $this->writer->insertPoint('rg_3', $this->pp("r_duration_results"), $rTime);
                break;
        }

    }
    
    public function insertFinalRound(int $teamCount, TimeCursor $time): void
    {
        switch ($teamCount) {
            case 16:
                $this->writer->withGroup('r_final_16', function () use ($time) {
                    // 4 tables alternating
                    for ($i = 0; $i < 4; $i++) {
                        $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_16"));
                        $time->addMinutes($this->pp("r_duration_next_start"));

                        $this->insertOneMatch($time, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_16"));
                        $time->addMinutes($i < 3 ? $this->pp("r_duration_next_start") : $this->pp("r_duration_match"));
                    }

                    if ($this->pp("r_robot_check_16")) {
                        $time->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $time->addMinutes($this->pp("r_duration_results"));
                });
                break;

            case 8:
                $this->writer->withGroup('r_final_8', function () use ($time) {
                    if ($this->pp("r_tables") == 2) {
                        for ($i = 0; $i < 4; $i++) {
                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_8"));
                            $time->addMinutes($this->pp("r_duration_match"));
                        }
                    } else {
                        for ($i = 0; $i < 2; $i++) {
                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_8"));
                            $time->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_8"));
                            $time->addMinutes($i < 1 ? $this->pp("r_duration_next_start") : $this->pp("r_duration_match"));
                        }
                    }

                    if ($this->pp("r_robot_check_8")) {
                        $time->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $time->addMinutes($this->pp("r_duration_results"));
                });
                break;

            case 4:
                $this->writer->withGroup('r_final_4', function () use ($time) {
                    if ($this->pp("r_quarter_final")) {
                        // TODO texts: QF1..QF4
                        if ($this->pp("r_tables") == 2) {
                            for ($i = 0; $i < 2; $i++) {
                                $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                                $time->addMinutes($this->pp("r_duration_match"));
                            }
                        } else {
                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                            $time->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_4"));
                            $time->addMinutes($this->pp("r_duration_match"));
                        }
                    } else {
                        // TODO texts: RG1..RG4
                        if ($this->pp("r_tables") == 2) {
                            for ($i = 0; $i < 2; $i++) {
                                $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                                $time->addMinutes($this->pp("r_duration_match"));
                            }
                        } else {
                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                            $time->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($time, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_4"));
                            $time->addMinutes($this->pp("r_duration_match"));
                        }
                    }

                    if ($this->pp("r_robot_check_4")) {
                        $time->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $this->writer->insertPoint('rg_semi_final', $this->pp("r_duration_results"), $time);
                });
                break;

            case 2:
                $this->writer->withGroup('r_final_2', function () use ($time) {
                    $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_2"));
                    $time->addMinutes($this->pp("r_duration_match"));

                    if ($this->pp("r_robot_check_2")) {
                        $time->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $this->insertOneMatch($time, $this->pp("r_duration_match"), 1, 0, 2, 0, false);
                    $time->addMinutes($this->pp("r_duration_match"));

                    $this->writer->insertPoint('rg_final', $this->pp("c_ready_awards"), $time);
                });
                break;
        }
    }
}