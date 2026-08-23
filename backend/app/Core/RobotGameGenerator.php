<?php

namespace App\Core;
use App\Core\TimeCursor;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Support\MatchPlan;
use App\Enums\ExploreMode;

/**
 * Writes Challenge robot-game activities onto rTime.
 *
 * Who plays whom lives in MatchPlan (built by ChallengeMatchPlanBuilder).
 * This class only places those matches on the clock, plus finals (no stored opponents).
 */
class RobotGameGenerator
{
    use UsesPlanParameter;

    private ActivityWriter $writer;
    private TimeCursor $rTime;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    private MatchPlan $matchPlan;

    public function __construct(
        ActivityWriter $writer,
        PlanParameter $params,
        TimeCursor $rTime,
        IntegratedExploreState $integratedExplore,
        MatchPlan $matchPlan
    ) {
        $this->writer = $writer;
        $this->params = $params;  // Required for trait
        $this->rTime = $rTime;
        $this->integratedExplore = $integratedExplore;
        $this->matchPlan = $matchPlan;
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
        // The time provided to the function is the start time of the match, regardless of robot check.

        // $time is local to this function. $r_time needs to be adjusted by the caller of this function.


        // Clone so we correctly capture the start time for robot check/match
        $time = $rTime->copy();

        // Write NULL for team slots when team id is 0 (e.g. final rounds)
        $table1Team = $team1 === 0 ? null : $team1;
        $table2Team = $team2 === 0 ? null : $team2;

        // With robot check → first enter check, then start match
        if ($robotCheck) {
            $this->writer->insertActivity(
                'r_check',
                $time,
                $this->pp('r_duration_robot_check'),
                null,
                null,
                $table1,
                $table1Team,
                $table2,
                $table2Team
            );

            // Advance time
            $time->addMinutes($this->pp('r_duration_robot_check'));
        }

        // Enter match
        $this->writer->insertActivity(
            'r_match',
            $time,
            $duration,
            null,
            null,
            $table1,
            $table1Team,
            $table2,
            $table2Team
        );
    }    


    public function insertOneRound(int $round)
    {
        // 1) Set activity group based on round
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

        // 2) Filter and sort matches for this round
        $filtered = $this->matchPlan->entriesForRound($round);

        // 3) Prepare activities for bulk insert
        $activities = [];
        
        foreach ($filtered as $match) {
            // Determine duration (TR vs RG)
            $duration = ($round === 0)
                ? $this->pp("r_duration_test_match")
                : $this->pp("r_duration_match");

            // Exotic case: skip empty TR match
            if ($match['team_1'] === 0 && $match['team_2'] === 0) {
                // Update time but don't create activity
                $this->advanceTimeForMatch($round, $match, $duration);
                continue;
            }

            // Clone time for this match
            $time = $this->rTime->copy();

            // Add robot check activity if needed
            if ($this->pp("r_robot_check")) {
                $activities[] = $this->prepareActivity(
                    'r_check',
                    $time,
                    $this->pp('r_duration_robot_check'),
                    null, null,
                    $match['table_1'], $match['team_1'],
                    $match['table_2'], $match['team_2']
                );
                
                $time->addMinutes($this->pp('r_duration_robot_check'));
            }

            // Add match activity
            $activities[] = $this->prepareActivity(
                'r_match',
                $time,
                $duration,
                null, null,
                $match['table_1'], $match['team_1'],
                $match['table_2'], $match['team_2']
            );

            // Advance main time cursor
            $this->advanceTimeForMatch($round, $match, $duration);
        }

        // Bulk insert all activities for this round
        if (!empty($activities)) {
            $this->writer->insertActivitiesBulk($activities);
        }

        // 5) Robot check adds additional time at the end of the round
        if ($this->pp("r_robot_check")) {
            $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
        }

        // 6) Fix for 4 tables: when last match is over, correct total duration
        if ($this->pp("r_tables") === 4) {
            $delta = $this->pp("r_duration_match") - $this->pp("r_duration_next_start");
            $this->rTime->addMinutes($delta);
        }

        // 7) Breaks before the next round
        switch ($round) {
            case 0:
                // Test round: Handle early lunch break if enabled
                if ($this->pp('c_lunch_break_early') && $this->pp('c_duration_lunch_break') == 0) {
                    // Early lunch: use lunch duration instead of regular break
                    $this->rTime->addMinutes($this->pp("r_duration_lunch"));
                } else {
                    // Normal: regular break after test round
                    $this->rTime->addMinutes($this->pp("r_duration_break"));
                }
                break;

            case 1:
                if ($this->pp('g_finale')) {
                    // Finale: Simple break after RG1
                    $this->rTime->addMinutes($this->pp("r_duration_break"));
                } else {
                    // Challenge break is the floor for RG2. Explore may only push rTime later.
                    $rg1End = $this->rTime->current();
                    $this->integratedExplore->rg1End = $rg1End;

                    if (!$this->pp('c_lunch_break_early') && $this->pp('c_duration_lunch_break') === 0) {
                        $this->rTime->addMinutes($this->pp("r_duration_lunch"));
                    }

                    if ($this->pp("e_mode") == ExploreMode::INTEGRATED_AFTERNOON->value) {
                        $this->integratedExplore->startTime = clone $rg1End;
                        $exploreHoleEnd = new TimeCursor($rg1End);
                        $exploreHoleEnd->addMinutes($this->integratedExplore->duration);
                        $this->rTime->advanceToLater($exploreHoleEnd->current());
                    }
                }
                break;

            case 2:
                if ($this->pp('g_finale')) {
                    // Finale: Everything that was in case 1 for normal events
                    if ($this->pp("e_mode") == ExploreMode::INTEGRATED_MORNING->value || 
                        $this->pp("e_mode") == ExploreMode::INTEGRATED_AFTERNOON->value) {
                        // Integrated Explore mode: coordinate with ExploreGenerator
                        $this->integratedExplore->startTime = $this->rTime->current();
                        $this->rTime->addMinutes($this->integratedExplore->duration);
                    } else {
                        // Skip lunch pause if early lunch is enabled (lunch already handled at test round)
                        if (!$this->pp('c_lunch_break_early') && $this->pp('c_duration_lunch_break') === 0) {
                            $this->rTime->addMinutes($this->pp("r_duration_lunch"));
                        }
                    }
                } else {
                    // Normal events: Regular break after RG2 (lunch was already handled at test round if early)
                    $this->rTime->addMinutes($this->pp("r_duration_break"));
                }
                break;

            case 3:
                // Insert point after RG3 is now handled in ChallengeGenerator::robotGameFinals()
                // to support flexible presentation scheduling
                break;
        }

    }

    /**
     * Prepare activity data for bulk insert
     */
    private function prepareActivity(
        string $activityTypeCode,
        TimeCursor $time,
        int $duration,
        ?int $juryLane, ?int $juryTeam,
        ?int $table1, ?int $table1Team,
        ?int $table2, ?int $table2Team
    ): array {
        $start = $time->current()->format('Y-m-d H:i:s');
        $endCursor = $time->copy();
        $endCursor->addMinutes($duration);
        $end = $endCursor->current()->format('Y-m-d H:i:s');

        return [
            'activityTypeCode' => $activityTypeCode,
            'start' => $start,
            'end' => $end,
            'juryLane' => $juryLane,
            'juryTeam' => $juryTeam,
            'table1' => $table1,
            'table1Team' => $table1Team,
            'table2' => $table2,
            'table2Team' => $table2Team,
        ];
    }

    /**
     * Advance time cursor based on match configuration
     */
    private function advanceTimeForMatch(int $round, array $match, int $duration): void
    {
        if ($this->pp("r_tables") === 2) {
            // 2 tables: Next match waits until this one is finished
            $this->rTime->addMinutes($duration);
        } else {
            // 4 tables
            if ($round === 0) {
                // TR: Start times alternate between next_start and (match - next_start)
                if (($match['match']) % 2 === 1) {
                    $this->rTime->addMinutes($this->pp("r_duration_next_start"));
                } else {
                    $delta = $duration - $this->pp("r_duration_next_start");
                    $this->rTime->addMinutes($delta);
                }
            } else {
                // RG1–3: Overlap — next start every r_duration_next_start
                $this->rTime->addMinutes($this->pp("r_duration_next_start"));
            }
        }
    }
    
    public function insertFinalRound(int $teamCount, bool $skipPause = false): void
    {
        switch ($teamCount) {
            case 16:
                $this->writer->withGroup('r_final_16', function () use ($skipPause) {
                    // 4 tables alternating
                    for ($i = 0; $i < 4; $i++) {
                        $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_16"));
                        $this->rTime->addMinutes($this->pp("r_duration_next_start"));

                        $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_16"));
                        $this->rTime->addMinutes($i < 3 ? $this->pp("r_duration_next_start") : $this->pp("r_duration_match"));
                    }

                    if ($this->pp("r_robot_check_16")) {
                        $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    if (!$skipPause) {
                        $this->rTime->addMinutes($this->pp("r_duration_results"));
                    }
                });
                break;

            case 8:
                $this->writer->withGroup('r_final_8', function () use ($skipPause) {
                    if ($this->pp("r_tables") == 2) {
                        for ($i = 0; $i < 4; $i++) {
                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_8"));
                            $this->rTime->addMinutes($this->pp("r_duration_match"));
                        }
                    } else {
                        for ($i = 0; $i < 2; $i++) {
                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_8"));
                            $this->rTime->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_8"));
                            $this->rTime->addMinutes($i < 1 ? $this->pp("r_duration_next_start") : $this->pp("r_duration_match"));
                        }
                    }

                    if ($this->pp("r_robot_check_8")) {
                        $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    if (!$skipPause) {
                        $this->rTime->addMinutes($this->pp("r_duration_results"));
                    }
                    
                });
                break;

            case 4:
                $this->writer->withGroup('r_final_4', function () use ($skipPause) {
                    if ($this->pp("r_final_8")) {
                        // TODO texts: QF1..QF4
                        if ($this->pp("r_tables") == 2) {
                            for ($i = 0; $i < 2; $i++) {
                                $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                                $this->rTime->addMinutes($this->pp("r_duration_match"));
                            }
                        } else {
                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                            $this->rTime->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_4"));
                            $this->rTime->addMinutes($this->pp("r_duration_match"));
                        }
                    } else {
                        // TODO texts: RG1..RG4
                        if ($this->pp("r_tables") == 2) {
                            for ($i = 0; $i < 2; $i++) {
                                $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                                $this->rTime->addMinutes($this->pp("r_duration_match"));
                            }
                        } else {
                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_4"));
                            $this->rTime->addMinutes($this->pp("r_duration_next_start"));

                            $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 3, 0, 4, 0, $this->pp("r_robot_check_4"));
                            $this->rTime->addMinutes($this->pp("r_duration_match"));
                        }
                    }

                    if ($this->pp("r_robot_check_4")) {
                        $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    if (!$skipPause) {
                        $this->rTime->addMinutes($this->pp("r_duration_results"));
                    }
                });
                break;

            case 2:
                $this->writer->withGroup('r_final_2', function () use ($skipPause) {
                    $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_2"));
                    $this->rTime->addMinutes($this->pp("r_duration_match"));

                    if ($this->pp("r_robot_check_2")) {
                        $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, false);
                    $this->rTime->addMinutes($this->pp("r_duration_match"));

                    if (!$skipPause) {
                        $this->rTime->addMinutes($this->pp("c_ready_awards"));
                    }
                });
                break;
        }
    }
}