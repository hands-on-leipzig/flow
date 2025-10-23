<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Core\RobotGameGenerator;
use App\Core\TimeCursor;


class ChallengeGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $cTime;
    private RobotGameGenerator $matchPlan;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    use UsesPlanParameter;

    public function __construct(
        ActivityWriter $writer, 
        PlanParameter $params,
        IntegratedExploreState $integratedExplore
    ) {
        $this->writer = $writer;
        $this->params = $params;
        $this->integratedExplore = $integratedExplore;
        
        // Create time cursors from base date
        $baseDate = $params->get('g_date');
        $this->cTime = new TimeCursor(clone $baseDate);
        $this->jTime = new TimeCursor(clone $baseDate);
        $this->rTime = new TimeCursor(clone $baseDate);

        // Derived parameters 
        $cTeams = $params->get('c_teams');
        $jLanes = $params->get('j_lanes');
        $rTables = $params->get('r_tables');

        $jRounds = (int) ceil($cTeams / max(1, $jLanes));
        $params->add('j_rounds', $jRounds, 'integer');

        $matchesPerRound = (int) ceil($cTeams / 2);
        $params->add('r_matches_per_round', $matchesPerRound, 'integer');

        $needVolunteer = $matchesPerRound != ($cTeams / 2);
        $params->add('r_need_volunteer', $needVolunteer, 'boolean');

        $asym = $rTables == 4 && (($cTeams % 4 == 1) || ($cTeams % 4 == 2));
        $params->add('r_asym', $asym, 'boolean');

    }

    public function judgingOneRound(int $cBlock, int $jT): void
    {
        // Capture jTime reference before entering closure
        $jTime = $this->jTime;
        
        $this->writer->withGroup('j_package', function () use ($cBlock, $jT, $jTime) {

            $activities = [];

            // 1) Judging WITH team - prepare all activities
            $withTeamStart = $jTime->current()->format('Y-m-d H:i:s');
            $withTeamEndCursor = $jTime->copy();
            $withTeamEndCursor->addMinutes($this->pp('j_duration_with_team'));
            $withTeamEnd = $withTeamEndCursor->current()->format('Y-m-d H:i:s');
            
            for ($jL = 1; $jL <= $this->pp('j_lanes'); $jL++) {
                if ($jT + $jL <= $this->pp('c_teams')) {
                    $activities[] = [
                        'activityTypeCode' => 'j_with_team',
                        'start' => $withTeamStart,
                        'end' => $withTeamEnd,
                        'juryLane' => $jL,
                        'juryTeam' => $jT + $jL,
                    ];
                }
            }
            $jTime->addMinutes($this->pp('j_duration_with_team'));

            // 2) Scoring WITHOUT team - prepare all activities
            $scoringStart = $jTime->current()->format('Y-m-d H:i:s');
            $scoringEndCursor = $jTime->copy();
            $scoringEndCursor->addMinutes($this->pp('j_duration_scoring'));
            $scoringEnd = $scoringEndCursor->current()->format('Y-m-d H:i:s');
            
            for ($jL = 1; $jL <= $this->pp('j_lanes'); $jL++) {
                if ($jT + $jL <= $this->pp('c_teams')) {
                    $activities[] = [
                        'activityTypeCode' => 'j_scoring',
                        'start' => $scoringStart,
                        'end' => $scoringEnd,
                        'juryLane' => $jL,
                        'juryTeam' => $jT + $jL,
                    ];
                }
            }
            $jTime->addMinutes($this->pp('j_duration_scoring'));

            // Bulk insert all judging activities for this round
            if (!empty($activities)) {
                $this->writer->insertActivitiesBulk($activities);
            }

            // 3) Pause / Lunch nach Runde
            if (($this->pp('j_rounds') == 4 && $cBlock == 2) ||
                ($this->pp('j_rounds') > 4 && $cBlock == 3)) {
                if ($this->pp('c_duration_lunch_break') == 0) {
                    $jTime->addMinutes($this->pp('j_duration_lunch'));
                }
            } elseif ($cBlock < $this->pp('j_rounds')) {
                $jTime->addMinutes($this->pp('j_duration_break'));
            }
        });
    }

    public function openingsAndBriefings(bool $explore = false): void
    {
        // Log::info('ChallengeGenerator: Starting openings and briefings', ['explore' => $explore]);

        try {
            
            if ($explore) {

                $this->cTime->setTime($this->pp('g_start_opening'));
                $this->jTime->set($this->cTime->current());
                $this->rTime->set($this->cTime->current());

                $this->writer->withGroup('g_opening', function () {
                    $this->writer->insertActivity('g_opening', $this->cTime, $this->pp('g_duration_opening'));
                });

                $this->jTime->addMinutes($this->pp('g_duration_opening'));
                $this->rTime->addMinutes($this->pp('g_duration_opening'));

                // Log::info('Explore integrated morning: teams=' . $this->pp('e1_teams') . ', lanes=' . $this->pp('e1_lanes') . ', rounds=' . $this->pp('e1_rounds'));

            } else {

                $this->cTime->setTime($this->pp('c_start_opening'));
                $this->jTime->set($this->cTime->current());
                $this->rTime->set($this->cTime->current());

                $this->writer->withGroup('c_opening', function () {
                    $this->writer->insertActivity('c_opening', $this->cTime, $this->pp('c_duration_opening'));
                });

                $this->jTime->addMinutes($this->pp('c_duration_opening'));
                $this->rTime->addMinutes($this->pp('c_duration_opening'));

                // Log::debug('Explore no integrated morning batch');
            }

            $this->briefings($this->cTime->current());

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in openings and briefings', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate openings and briefings: {$e->getMessage()}", 0, $e);
        }
    }

    public function briefings(\DateTime $t): void
    {
        // Coach briefing - skip for finale (already on Day 1)
        if (!$this->pp('g_finale')) {
            $this->writer->withGroup('c_briefing', function () use ($t) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($this->pp('c_duration_briefing') + $this->pp('c_ready_opening'));
                $this->writer->insertActivity('c_briefing', $cursor, $this->pp('c_duration_briefing'));
            });
        }
        
        // Jury briefing - same logic for finale and normal events
        $this->writer->withGroup('j_briefing', function () use ($t) {
            if (!$this->pp('j_briefing_after_opening')) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($this->pp('j_duration_briefing') + $this->pp('c_ready_opening'));
                $this->writer->insertActivity('j_briefing', $cursor, $this->pp('j_duration_briefing'));
                

            } else {
                $cursor = $this->jTime->copy();
                $cursor->addMinutes($this->pp('j_ready_briefing'));
                $this->writer->insertActivity('j_briefing', $cursor, $this->pp('j_duration_briefing'));
                $this->jTime->addMinutes($this->pp('j_ready_briefing') + $this->pp('j_duration_briefing') );
            }
            $this->jTime->addMinutes($this->pp('j_ready_action'));
        });

        // Referee briefing - use r_duration_briefing_2 for finale Day 2
        $refereeBriefingDuration = $this->pp('g_finale') 
            ? $this->pp('r_duration_briefing_2') 
            : $this->pp('r_duration_briefing');

        $this->writer->withGroup('r_briefing', function () use ($t, $refereeBriefingDuration) {
            if (!$this->pp('r_briefing_after_opening')) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($refereeBriefingDuration + $this->pp('c_ready_opening'));
                $this->writer->insertActivity('r_briefing', $cursor, $refereeBriefingDuration);
            } else {
                $cursor = $this->rTime->copy();
                $cursor->addMinutes($this->pp('r_ready_briefing'));
                $this->writer->insertActivity('r_briefing', $cursor, $refereeBriefingDuration);
                $this->rTime->addMinutes($this->pp('r_ready_briefing') + $refereeBriefingDuration);
            }

            $this->rTime->addMinutes($this->pp('r_ready_action'));
        });

    }



    public function main(bool $explore = false)
    {
        Log::info('ChallengeGenerator::main', [
            'plan_id' => $this->pp('g_plan'),
            'c_teams' => $this->pp('c_teams'),
            'j_lanes' => $this->pp('j_lanes'),
            'j_rounds' => $this->pp('j_rounds'),
            'r_tables' => $this->pp('r_tables'),
            'explore' => $explore,
        ]);

        try {
            // Instantiate match plan for Challenge domain (needs rTime to be initialized)
            $this->matchPlan = new RobotGameGenerator(
                $this->writer, 
                $this->params, 
                $this->rTime,
                $this->integratedExplore
            );
            $this->matchPlan->createMatchPlan();
            
            // Apply match rotation to improve Q2 (table diversity) and Q3 (opponent diversity)
            $this->matchPlan->applyMatchRotation();

            // -----------------------------------------------------------------------------------
            // FLL Challenge: Put the judging / robot game schedule together
            // -----------------------------------------------------------------------------------

            // Current time is the earliest available time.
            $jTimeEarliest = clone $this->jTime; // In block 1 judging starts immediately. No need to compare with robot game.

            $cBlock = 0;
            $jT = 0; // first team index for this block

            // Time for judging (T4J) = how long will a team be away to judging and thus not available for robot game.
            $jT4J = $this->pp('j_duration_with_team') + $this->pp('c_duration_transfer');

            // Create the blocks of judging with robot game aligned
            for ($cBlock = 1; $cBlock <= $this->pp('j_rounds'); $cBlock++) {

                log::debug("Before: cBlock: {$cBlock}, jTime: {$this->jTime->current()->format('H:i')}, rTime: {$this->rTime->current()->format('H:i')}, jTimeEarliest: {$jTimeEarliest->current()->format('H:i')}");


                // -----------------------------------------------------------------------------------
                // Adjust timing between judging and robot game
                // -----------------------------------------------------------------------------------

                // duration of one match: test round or normal
                // For finale events, all Day 2 rounds are normal rounds (no test rounds on Day 2)
                $rDuration = ($cBlock == 1 && !$this->pp('g_finale'))
                    ? $this->pp('r_duration_test_match')   // Test round (only for non-finale events)
                    : $this->pp('r_duration_match');        // Normal round (all finale rounds, or non-finale block > 1)

                // Key concept 1: teams first in robot game go to judging in NEXT round
                // 
                // available for judging = time from start of robot game round to being in front of judges' room
                // Calculate forward from start of the round:
                // 1 or 2 lanes = 1 match
                // 3 or 4 lanes = 2 matches
                // 5 or 6 lanes = 3 matches

                // The calculation of a4j = "available for judging" is down below
                // Here the value of the last block is used.    

                // Delay judging if needed
                if ($this->jTime->current() < $jTimeEarliest->current()) {
                    Log::debug("Judging delayed to: {$jTimeEarliest->format('H:i')}");
                    $this->jTime->set($jTimeEarliest->current());
                }

                // Key concept 2: teams at judging are last in CURRENT robot game round
                // 
                // number of matches before (MB) teams must be back from judging
                if ($cBlock == $this->pp('j_rounds') && ($this->pp('c_teams') % $this->pp('j_lanes')) !== 0) {
                    // not all lanes filled in last round of judging
                    $rMB = $this->pp('r_matches_per_round') - ceil(($this->pp('c_teams') % $this->pp('j_lanes')) / 2);
                } else {
                    $rMB = $this->pp('r_matches_per_round') - ceil($this->pp('j_lanes') / 2);
                }

                // If asymmetrical match plan, one empty match is added into the test round.
                if ($cBlock == 1 && $this->pp('r_asym') && $this->pp("j_rounds") != 4) {
                    $rMB++;
                }

                // Calculate time to START of match
                if ($this->pp('r_tables') == 2) {
                    // matches START in sequence
                    $rT2M = $rMB * $rDuration;                                                           
                } else {
                    // matches START alternating with respective delay between STARTs
                    if ($rMB % 2 === 0) {
                        $rT2M = ($rMB / 2 - 1) * $rDuration + $this->pp('r_duration_next_start');
                    } else {
                        $rT2M = (($rMB - 1) / 2) * $rDuration;
                    }
                }

                // Note: No need to consider robot check!
                // It delays the match start, but the teams have been there ealier for exactly the same amount of time.

                // Compare time away for judging and expectations from robot game
                // Calculate target start time for robot game

                // rStartTarget = jTime + (T4J - T2M)
                $rStartTarget = clone $this->jTime;
                $rStartTarget->addMinutes($jT4J - $rT2M);

                // If rTime <= rStartTarget then rTime = rStartTarget
                if ($this->rTime->current() <= $rStartTarget->current()) {
                    $this->rTime->set($rStartTarget->current());
                    Log::debug("Robot game delayed to: {$this->rTime->format('H:i')}");
                }

                // -----------------------------------------------------------------------------------
                // Calculate a4j for concept 1 above
                // -----------------------------------------------------------------------------------

                if ($this->pp('j_rounds') > 4 && $cBlock == 2 || $this->pp('j_rounds') == 6 && $cBlock == 5) {

                        // for plans with more than 4 rounds, juding rounds 1 and 2 are aligned with just one robot game round
                        // for plans with 6 rounds, judging rounds 5 and 6 are aligned with just one robot game round
                        $rA4J = 0; 

                } else {

                    $rMB = ceil($this->pp('j_lanes') / 2);

                    // calculate time to END of the match
                    if ($this->pp('r_tables') == 2) {
                        $rA4J = $rMB * $rDuration;
                    } else {
                        if ($rMB % 2 === 0) {
                            $rA4J = $rMB / 2 * $rDuration + $this->pp('r_duration_next_start');
                        } else {
                            $rA4J = ($rMB + 1) / 2 * $rDuration;
                        }
                    }
        
                    // Robot check shifts everything, but just once.
                    if ($this->pp('r_robot_check')) {
                        $rA4J += $this->pp('r_duration_robot_check');
                    }

                    // Time for transfer from robot game to judges' room
                    $rA4J += $this->pp('c_duration_transfer');

                }
                
                // Store this as time object
                $jTimeEarliest = clone $this->rTime;
                $jTimeEarliest->addMinutes($rA4J);

                log::debug("After: cBlock: {$cBlock}, jTime: {$this->jTime->current()->format('H:i')}, rTime: {$this->rTime->current()->format('H:i')}, jTimeEarliest: {$jTimeEarliest->current()->format('H:i')}");


                // -----------------------------------------------------------------------------------
                // Now we are ready to create activities for robot game and then judging
                // -----------------------------------------------------------------------------------

                // judging including breaks
                $this->judgingOneRound($cBlock, $jT);

                // First team to start with in next block
                $jT += $this->pp('j_lanes');

                // Robot Game rounds depending on block and config
                if ($this->pp('g_finale')) {
                    // Finale Day 2: TR already on Day 1, start with regular rounds
                    switch ($cBlock) {
                        case 1:
                            $this->matchPlan->insertOneRound(1);  // R1
                            break;
                        case 2:
                            // No robot game
                            break;
                        case 3:
                            $this->matchPlan->insertOneRound(2);  // R2
                            break;
                        case 4:
                            $this->matchPlan->insertOneRound(3);  // R3
                            break;
                        case 5:
                            // No robot game (Finals or Awards will be added separately)
                            break;
                    }
                } else {
                    // Normal event mapping
                    switch ($cBlock) {
                        case 1:
                            // First judging round runs parallel to RG test round, regardless of j_rounds
                            $this->matchPlan->insertOneRound(0);
                            break;
                        case 2:
                            if ($this->pp('j_rounds') == 4) {
                                $this->matchPlan->insertOneRound(1);
                            }
                            break;
                        case 3:
                            if ($this->pp('j_rounds') == 4) {
                                $this->matchPlan->insertOneRound(2);
                            } else {
                                $this->matchPlan->insertOneRound(1);
                            }
                            break;
                        case 4:
                            if ($this->pp('j_rounds') == 4) {
                                $this->matchPlan->insertOneRound(3);
                            } else {
                                $this->matchPlan->insertOneRound(2);
                            }
                            break;
                        case 5:
                            $this->matchPlan->insertOneRound(3);
                            break;
                        case 6:
                            // No robot game left
                            break;
                    }
                }

                // -----------------------------------------------------------------------------------
                // If a hard lunch break is set, do it here
                // -----------------------------------------------------------------------------------
                if (
                    (($this->pp('j_rounds') == 4 && $cBlock == 2) ||
                    ($this->pp('j_rounds') > 4 && $cBlock == 3)) &&
                    $this->pp('c_duration_lunch_break') > 0
                ) {
                    // Align both timelines
                    if ($this->rTime->current() < $this->jTime->current()) {
                        $this->rTime->set($this->jTime->current());
                    } else {
                        $this->jTime->set($this->rTime->current());
                    }

                    $this->writer->withGroup('c_lunch_break', function () {
                        $this->writer->insertActivity('c_lunch_break', $this->jTime, $this->pp('c_duration_lunch_break'));
                    });

                    $this->jTime->addMinutes($this->pp('c_duration_lunch_break'));
                    $this->rTime->addMinutes($this->pp('c_duration_lunch_break'));
                }
            }

            // -----------------------------------------------------------------------------------
            // Synchronize after judging and robot game
            // -----------------------------------------------------------------------------------
            
            
            $this->cTime->set($this->jTime->current());
            $this->cTime->addMinutes(-$this->pp('j_duration_scoring'));

            // If RG is later, their time wins
            if ($this->rTime->current() > $this->cTime->current()) {
                $this->cTime->set($this->rTime->current());        
            } 

            // -----------------------------------------------------------------------------------
            // FLL Challenge: Deliberations
            // -----------------------------------------------------------------------------------

            // Move to judges main room
            $this->jTime->addMinutes($this->pp('j_ready_deliberations'));

            // Deliberation
            $this->writer->withGroup('j_deliberations', function () {
                $this->writer->insertActivity('j_deliberations', $this->jTime, $this->pp('j_duration_deliberations'));
            });
            $this->jTime->addMinutes($this->pp('j_duration_deliberations'));

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in main challenge generation', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate main challenge activities: {$e->getMessage()}", 0, $e);
        }
    }


    public function robotGameFinals(): void
    {
        Log::info('ChallengeGenerator::robotGameFinals', [
            'plan_id' => $this->pp('g_plan'),
            'c_teams' => $this->pp('c_teams'),
        ]);
        
        try {
            // -----------------------------------------------------------------------------------
            // FLL Challenge: Everything after judging / robot game rounds
            // -----------------------------------------------------------------------------------
            //  
            // 1 Selected research on main stage
            // 2 followed by robot game finals
            // 3 awards
            //
            // 1 and 2 may be flipped

            // -----------------------------------------------------------------------------------
            // FLL Challenge: Research presentations on stage
            // -----------------------------------------------------------------------------------
            // Organizer may chose not to show any presentations.
            // They can also decide to show them at the end

            // As of now nothing runs in parallel to robot game, but we use r_time anyway to be more open for future changes
            $this->rTime->set($this->cTime->current());

            if ($this->pp('c_presentations') == 0 || $this->pp('c_presentations_last')) {

                // Break for referees and time to annouce advancing teams
                $this->rTime->addMinutes($this->pp('r_duration_break'));

            } else {

                // Research presentations on stage
                $this->rTime->addMinutes($this->pp('c_ready_presentations'));

                // Nutzt den existierenden ChallengeGenerator
                $this->presentations();
                // ChallengeGenerator verschiebt $rTime intern bereits um die Dauer
            }

            // -----------------------------------------------------------------------------------
            /// Robot-game final rounds
            // -----------------------------------------------------------------------------------

            // Round of best 16 (optional, only for finale events)
            if ($this->pp('g_finale') && $this->pp('r_final_16')) {
                $this->matchPlan->insertFinalRound(16);
            }

            // Round of best 8 (optional, auto-enabled if r_final_16 is active)
            if ($this->pp('r_final_8') || $this->pp('r_final_16')) {
                $this->matchPlan->insertFinalRound(8);
            }

            // Semi finale is a must
            $this->matchPlan->insertFinalRound(4);

            // Final matches
            $this->matchPlan->insertFinalRound(2);

            // -----------------------------------------------------------------------------------
            // FLL Challenge: Research presentations on stage
            // -----------------------------------------------------------------------------------
            if ($this->pp('c_presentations') > 0 && $this->pp('c_presentations_last')) {
                // Research presentations on stage
                $this->rTime->addMinutes($this->pp('c_ready_presentations'));

                $this->presentations();
            }

    
            // back to only one action a time
            $this->cTime->set($this->rTime->current());

            // FLL Challenge
            // Deliberations might have taken longer, which is unlikely
            if ($this->jTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
                $this->cTime->set($this->jTime->current());
            }

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in robot game finals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate robot game finals: {$e->getMessage()}", 0, $e);
        }
    }
    
    public function presentations(): void
    {
        $duration = $this->pp('c_presentations') * $this->pp('c_duration_presentation') + 5;

        $this->writer->withGroup('c_presentations', function () use ($duration) {
            $this->writer->insertActivity('c_presentations', $this->rTime, $duration);
        });

        $this->rTime->addMinutes($duration);

        $insertPoint = $this->pp('c_presentations_last')
            ? 'c_ready_awards'
            : 'c_ready_presentations';

        $this->writer->insertPoint('c_after_presentations', $this->pp($insertPoint), $this->rTime);
    }


    public function awards( bool $explore = false): void
    {
        // Log::info('ChallengeGenerator: Starting awards', ['explore' => $explore]);

        try {
            if ($explore) {

            // Log::debug('Awards joint');

            $this->writer->withGroup('g_awards', function () {
                $this->writer->insertActivity('g_awards', $this->cTime, $this->pp('g_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('g_duration_awards'));

        } else {

            // Log::debug('Awards Challenge only');

            $this->writer->withGroup('c_awards', function () {
                $this->writer->insertActivity('c_awards', $this->cTime, $this->pp('c_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('c_duration_awards'));
        }

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in awards', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate awards: {$e->getMessage()}", 0, $e);
        }
    }

}