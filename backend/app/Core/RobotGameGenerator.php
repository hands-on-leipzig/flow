<?php

namespace App\Core;
use App\Core\TimeCursor;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use Illuminate\Support\Facades\DB;
use App\Models\MatchEntry;
use App\Enums\ExploreMode;
use App\Services\MatchRotationService;
use DateTime;

class RobotGameGenerator
{
    use UsesPlanParameter;

    private ActivityWriter $writer;
    private TimeCursor $rTime;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    private array $entries = [];

    public function __construct(
        ActivityWriter $writer, 
        PlanParameter $params, 
        TimeCursor $rTime,
        IntegratedExploreState $integratedExplore
    ) {
        $this->writer = $writer;
        $this->params = $params;  // Required for trait
        $this->rTime = $rTime;
        $this->integratedExplore = $integratedExplore;
    }

    // Create the robot game match plan regardless of the number of tables and timing

    public function createMatchPlan(): void
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
                if ($this->pp('g_finale')) {
                    // Finale Day 2: Different team starting positions (no TR on Day 2)
                    switch ($round) {
                        case 1:
                            $team = $this->pp("j_lanes") * 1;  // 1 * 5 = 5
                            break;
                        case 2:
                            $team = $this->pp("j_lanes") * 3;  // 3 * 5 = 15
                            break;
                        case 3:
                            $team = $this->pp("j_lanes") * 4;  // 4 * 5 = 20
                            break;
                    }
                } else {
                    // Normal event team starting positions
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

                            // Not all lanes may be filled in last judging round, 
                            // but that does not matter with six rounds, because robot game is aligned with judging 5
                    }

                    // If we have an odd number of teams, start with volunteer
                    if ($team == $this->pp("c_teams") && $this->pp("r_need_volunteer")) {
                        $team = $this->pp("c_teams") + 1;
                    }
                }
            }

            // Fill the match plan for the round starting with the last match, then going backwards
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
        if ($this->pp('r_asym') && $this->pp("j_rounds") != 4) {

            // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
            //
            // The issue only happens if r_asym is true
            // This means c_teams = 10, 14, 18, 22 or 26 teams (or one team less)

            // Solution is to add an empty match at tables 3+4 after j_lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan
            
            $newList = [];
            $emptyMatchInserted = false;
            
            foreach ($this->entries as $entry) {

                // For TR matches after j_lanes: increment match number to make room for empty match
                if ($entry['round'] === 0 && $entry['match'] > $this->pp("j_lanes")) {
                    $entry['match'] += 1;
                }

                // Copy all modified or unmodified entries
                $newList[] = $entry;
                
                // Insert empty match right after the last j_lanes match in TR
                if (!$emptyMatchInserted && $entry['round'] === 0 && $entry['match'] === $this->pp("j_lanes")) {
                    $newList[] = [
                        'round'   => 0,
                        'match'   => $this->pp("j_lanes") + 1,
                        'table_1' => 3,
                        'table_2' => 4,
                        'team_1'  => 0,
                        'team_2'  => 0,
                    ];
                    $emptyMatchInserted = true;
                }
            }

            $this->entries = $newList;
        }

        // Save match entries to database
        $this->saveMatchEntries();
    }

    /**
     * Apply match rotation service to improve Q2 (table diversity) and Q3 (opponent diversity)
     * for rounds 2 and 3.
     */
    public function applyMatchRotation(): void
    {
        $planId = $this->pp('g_plan');
        
        // Extract team sequences from current match plan
        $round1Seq = $this->extractRoundSequence(1);
        $round2Seq = $this->extractRoundSequence(2);
        $round3Seq = $this->extractRoundSequence(3);

        // Log::info("RobotGameGenerator: Match rotation starting", [
        //     'plan_id' => $planId,
        //     'c_teams' => $this->pp('c_teams'),
        //     'r_tables' => $this->pp('r_tables'),
        //     'j_lanes' => $this->pp('j_lanes'),
        //     'round1_seq' => $round1Seq,
        //     'round2_seq_before' => $round2Seq,
        //     'round3_seq_before' => $round3Seq,
        // ]);

        // Split rounds 2 and 3 into blocks (First, Middle, Last)
        $round2Blocks = $this->splitIntoBlocks($round2Seq);
        $round3Blocks = $this->splitIntoBlocks($round3Seq);

        // Log::info("RobotGameGenerator: Blocks split", [
        //     'plan_id' => $planId,
        //     'round2_blocks' => $round2Blocks,
        //     'round3_blocks' => $round3Blocks,
        // ]);

        // Apply rotation algorithm
        $rotationService = new MatchRotationService();
        $optimized = $rotationService->plan(
            $this->pp('r_tables'),
            $round1Seq,
            $round2Blocks,
            $round3Blocks
        );

        // Log::info("RobotGameGenerator: Rotation completed", [
        //     'plan_id' => $planId,
        //     'round2_seq_after' => $optimized['round2']['seq'],
        //     'round3_seq_after' => $optimized['round3']['seq'],
        //     'round2_pairs' => $optimized['round2']['pairs'],
        //     'round3_pairs' => $optimized['round3']['pairs'],
        // ]);

        // Update entries for rounds 2 and 3 with optimized sequences
        $this->applyOptimizedSequence(2, $optimized['round2']);
        $this->applyOptimizedSequence(3, $optimized['round3']);

        // Save the updated entries to database
        $this->saveMatchEntries();

        // Log::info("RobotGameGenerator: Match rotation applied and saved for rounds 2 and 3", [
        //     'plan_id' => $planId,
        // ]);
    }

    /**
     * Extract team sequence from a round in the current match plan.
     * Returns teams in match order (team_1, team_2, team_1, team_2, ...)
     *
     * @param int $round Round number (1, 2, or 3)
     * @return int[] Array of team IDs in sequence
     */
    private function extractRoundSequence(int $round): array
    {
        // Filter entries for this round
        $roundEntries = array_filter($this->entries, fn($e) => $e['round'] === $round);
        
        // Sort by match number
        usort($roundEntries, fn($a, $b) => $a['match'] <=> $b['match']);
        
        // Extract team sequence
        $sequence = [];
        foreach ($roundEntries as $entry) {
            $sequence[] = $entry['team_1'];
            $sequence[] = $entry['team_2'];
        }
        
        return $sequence;
    }

    /**
     * Split a team sequence into First, Middle, Last blocks based on j_lanes.
     * - First: first j_lanes teams
     * - Last: last j_lanes teams
     * - Middle: remaining teams
     *
     * @param int[] $sequence Team sequence
     * @return array{first: int[], middle: int[], last: int[]}
     */
    private function splitIntoBlocks(array $sequence): array
    {
        $jLanes = $this->pp('j_lanes');
        $total = count($sequence);
        
        // First j_lanes teams
        $first = array_slice($sequence, 0, $jLanes);
        
        // Last j_lanes teams
        $last = array_slice($sequence, $total - $jLanes, $jLanes);
        
        // Middle: everything between
        $middle = array_slice($sequence, $jLanes, $total - 2 * $jLanes);
        
        return [
            'first' => $first,
            'middle' => $middle,
            'last' => $last,
        ];
    }

    /**
     * Apply an optimized sequence to a round, updating entries.
     *
     * @param int $round Round number (2 or 3)
     * @param array{seq: int[], pairs: array<array{0:int,1:int}>, tables: array<int,int>} $optimized
     */
    private function applyOptimizedSequence(int $round, array $optimized): void
    {
        // Find all entries for this round
        $roundEntries = [];
        foreach ($this->entries as $idx => $entry) {
            if ($entry['round'] === $round) {
                $roundEntries[$idx] = $entry;
            }
        }
        
        // Sort by match number to get correct order
        uasort($roundEntries, fn($a, $b) => $a['match'] <=> $b['match']);
        
        // Apply optimized pairs to entries
        $pairIndex = 0;
        foreach ($roundEntries as $idx => $entry) {
            if ($pairIndex < count($optimized['pairs'])) {
                $pair = $optimized['pairs'][$pairIndex];
                $this->entries[$idx]['team_1'] = $pair[0];
                $this->entries[$idx]['team_2'] = $pair[1];
                $pairIndex++;
            }
        }
    }

    private function saveMatchEntries(): void
    {
        $planId = $this->pp('g_plan');
        
        // Clear existing match entries for this plan
        MatchEntry::where('plan', $planId)->delete();

        // Prepare data for bulk insert
        $data = array_map(function($entry) use ($planId) {
            return [
                'plan' => $planId,
                'round' => $entry['round'],
                'match_no' => $entry['match'],
                'table_1' => $entry['table_1'],
                'table_2' => $entry['table_2'],
                'table_1_team' => $entry['team_1'],
                'table_2_team' => $entry['team_2'],
            ];
        }, $this->entries);

        // Bulk insert all match entries in a single query
        if (!empty($data)) {
            MatchEntry::insert($data);
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
        // The time provided to the function is the start time of the match, regardless of robot check.

        // $time is local to this function. $r_time needs to be adjusted by the caller of this function.


        // Clone so we correctly capture the start time for robot check/match
        $time = $rTime->copy();

        // With robot check → first enter check, then start match
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
            $team1,
            $table2,
            $team2
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
        $filtered = array_filter($this->entries, fn ($m) => $m['round'] === $round);
        usort($filtered, fn ($a, $b) => $a['match'] <=> $b['match']);

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

        // 7) Inserted blocks / breaks for NEXT round
        switch ($round) {
            case 0:
                $this->writer->insertPoint('c_after_tr', $this->pp("r_duration_break"), $this->rTime);
                break;

            case 1:
                if ($this->pp("e_mode") == ExploreMode::INTEGRATED_MORNING->value || 
                    $this->pp("e_mode") == ExploreMode::INTEGRATED_AFTERNOON->value) {
                    // Integrated Explore mode: coordinate with ExploreGenerator
                    // Write start time for ExploreGenerator to pick up
                    // Log::debug("RobotGameGenerator: Inserting start time for ExploreGenerator: {$this->rTime->format('H:i')}");
                    $this->integratedExplore->startTime = $this->rTime->format('H:i');
                    
                    // Advance rTime by the duration that Explore will use
                    // (Duration was calculated by ExploreGenerator constructor)
                    // Log::debug("RobotGameGenerator: Advancing rTime by {$this->integratedExplore->duration} minutes");
                    $this->rTime->addMinutes($this->integratedExplore->duration);
                    
                    // Log::debug("RobotGameGenerator: rTime after advance: {$this->rTime->format('H:i')}");
                    
                } else {
                    if ($this->pp('c_duration_lunch_break') === 0) {
                        $this->writer->insertPoint('c_after_rg_1', $this->pp("r_duration_lunch"), $this->rTime);
                    }
                }
                break;

            case 2:
                $this->writer->insertPoint('c_after_rg_2', $this->pp("r_duration_break"), $this->rTime);
                break;

            case 3:
                $this->writer->insertPoint('c_after_rg_3', $this->pp("r_duration_results"), $this->rTime);
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
    
    public function insertFinalRound(int $teamCount): void
    {
        switch ($teamCount) {
            case 16:
                $this->writer->withGroup('r_final_16', function () {
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

                    $this->writer->insertPoint('c_after_final_16', $this->pp("r_duration_results"), $this->rTime);
                });
                break;

            case 8:
                $this->writer->withGroup('r_final_8', function () {
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

                    $this->writer->insertPoint('c_after_final_8', $this->pp("r_duration_results"), $this->rTime);
                    
                });
                break;

            case 4:
                $this->writer->withGroup('r_final_4', function () {
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

                    $this->writer->insertPoint('c_after_final_4', $this->pp("r_duration_results"), $this->rTime);
                });
                break;

            case 2:
                $this->writer->withGroup('r_final_2', function () {
                    $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, $this->pp("r_robot_check_2"));
                    $this->rTime->addMinutes($this->pp("r_duration_match"));

                    if ($this->pp("r_robot_check_2")) {
                        $this->rTime->addMinutes($this->pp("r_duration_robot_check"));
                    }

                    $this->insertOneMatch($this->rTime, $this->pp("r_duration_match"), 1, 0, 2, 0, false);
                    $this->rTime->addMinutes($this->pp("r_duration_match"));

                    $this->writer->insertPoint('c_after_final_2', $this->pp("c_ready_awards"), $this->rTime);
                });
                break;
        }
    }
}