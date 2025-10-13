<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Core\MatchPlan;


class ChallengeGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $cTime;
    private MatchPlan $matchPlan;

    use UsesPlanParameter;

    public function __construct(ActivityWriter $writer, TimeCursor $cTime, TimeCursor $jTime, TimeCursor $rTime, int $planId)
    {
        $this->writer = $writer;
        $this->rTime  = $rTime;
        $this->jTime  = $jTime;
        $this->cTime  = $cTime;

        // Instantiate match plan for Challenge domain
        $this->matchPlan = new MatchPlan($this->writer);

        // Derived parameters formerly computed in Core::initialize
        $params = PlanParameter::load($planId);
        $cTeams = (int) ($params->get('c_teams') ?? 0);
        if ($cTeams > 0) {
            $jLanes = (int) ($params->get('j_lanes') ?? 1);
            $rTables = (int) ($params->get('r_tables') ?? 0);

            $jRounds = (int) ceil($cTeams / max(1, $jLanes));
            $params->add('j_rounds', $jRounds, 'integer');

            $matchesPerRound = (int) ceil($cTeams / 2);
            $params->add('r_matches_per_round', $matchesPerRound, 'integer');

            $needVolunteer = $matchesPerRound != ($cTeams / 2);
            $params->add('r_need_volunteer', $needVolunteer, 'boolean');

            $asym = $rTables == 4 && (($cTeams % 4 == 1) || ($cTeams % 4 == 2));
            $params->add('r_asym', $asym, 'boolean');
        }
    }

    public function matchPlan(): MatchPlan
    {
        return $this->matchPlan;
    }

    
    

    public function judgingOneRound(int $cBlock, int $jT): void
    {
        $this->writer->withGroup('c_judging_package', function () use ($cBlock, $jT) {

            // 1) Judging WITH team
            for ($jLane = 1; $jLane <= pp('j_lanes'); $jLane++) {
                if ($jT + $jLane <= pp('c_teams')) {
                    $this->writer->insertActivity(
                        'c_with_team',
                        $this->jTime,
                        pp('j_duration_with_team'),
                        $jLane,
                        $jT + $jLane
                    );
                }
            }
            $this->jTime->addMinutes(pp('j_duration_with_team'));

            // 2) Scoring WITHOUT team
            for ($jLane = 1; $jLane <= pp('j_lanes'); $jLane++) {
                if ($jT + $jLane <= pp('c_teams')) {
                    $this->writer->insertActivity(
                        'c_scoring',
                        $this->jTime,
                        pp('j_duration_scoring'),
                        $jLane,
                        $jT + $jLane
                    );
                }
            }
            $this->jTime->addMinutes(pp('j_duration_scoring'));

            // 3) Pause / Lunch nach Runde
            if ((pp('j_rounds') == 4 && $cBlock == 2) ||
                (pp('j_rounds') > 4 && $cBlock == 3)) {
                if (pp('c_duration_lunch_break') == 0) {
                    $this->jTime->addMinutes(pp('j_duration_lunch'));
                }
            } elseif ($cBlock < pp('j_rounds')) {
                $this->jTime->addMinutes(pp('j_duration_break'));
            }
        });
    }

    public function main(bool $explore = false)
    {

         // Read all parameters for the plan. Access is via $this->pp('...')
        $gDate = clone $this->pp('g_date'); // DateTime

        $this->cDay = 0; // [Temp] Current day of the event. 1 = first day, 2 = second day, etc.

        if ($this->pp("c_teams") > 0) {

            // ***********************************************************************************
            // FLL Challenge (with our without FLL Explore)
            // ***********************************************************************************

            // -----------------------------------------------------------------------------------
            // Combine event date with start time of opening depending on the combination of
            // FLL Challenge and FLL Explore
            // -----------------------------------------------------------------------------------

            // For a finale the main action is on day 2, while LC is on day 1
            if ($this->pp('g_finale')) {

                // Save the day for Live Challenge
                $lcDate = clone $gDate;

                // Combine event date with start time of day 1   
                [$hours, $minutes] = explode(':', $this->pp('f_start_day_1'));
                $lcDate->setTime((int)$hours, (int)$minutes);
                $this->lcTime = new TimeCursor($lcDate);

                // Add one day for the main action
                $gDate->modify('+1 day');

                // To simplify branching in the challenge main day schedule, add an indicator
                $this->cDay = 2; // Day 2 of the event

            } else {

                $this->cDay = 1; // Day 1 of the event
                $this->lcTime = new TimeCursor(new DateTime()); // neutral placeholder
            }

            // -----------------------------------------------------------------------------------
            // Determine start time of opening depending on Explore mode
            // -----------------------------------------------------------------------------------
            if ($this->pp('e_mode') == ID_E_MORNING) {
                // FLL Challenge and Explore combined during the morning
                [$hours, $minutes] = explode(':', $this->pp('g_start_opening'));
            } else {
                // FLL Challenge stand-alone
                [$hours, $minutes] = explode(':', $this->pp('c_start_opening'));
            }

            $gDate->setTime((int)$hours, (int)$minutes);

            // -----------------------------------------------------------------------------------
            // Initialize Time Cursors for all main tracks
            // -----------------------------------------------------------------------------------
            $this->cTime = new TimeCursor(clone $gDate); // FLL Challenge
            $this->jTime = new TimeCursor(clone $gDate); // Judging in FLL Challenge
            $this->rTime = new TimeCursor(clone $gDate); // Robot game in FLL Challenge
            $this->eTime = new TimeCursor(clone $gDate); // FLL Explore judging

            $this->openings();
            $this->challenge();
            $this->awardsAndPostRounds();

            // -----------------------------------------------------------------------------------
            // FLL Explore decoupled from FLL Challenge
            // -----------------------------------------------------------------------------------

            if (
                $this->pp('e_mode') == ID_E_DECOUPLED_MORNING ||
                $this->pp('e_mode') == ID_E_DECOUPLED_AFTERNOON ||
                $this->pp('e_mode') == ID_E_DECOUPLED_BOTH
            ) {
                Log::debug('Explore decoupled mode active');

                // Decoupled Explore day(s)
                $explore = new ExploreGenerator($this->writer, $this->eTime, $this->rTime, $this->planId);

                // Gruppe 1 (morning)
                if (in_array($this->pp('e_mode'), [ID_E_DECOUPLED_MORNING, ID_E_DECOUPLED_BOTH]) && $this->pp('e1_teams') > 0) {
                    Log::debug('Explore decoupled morning group');
                    $this->explore->briefings($this->eTime, 1);
                    $this->explore->judging(1);
                    $this->explore->deliberationsAndAwards(1);
                }

                // Gruppe 2 (afternoon)
                if (in_array($this->pp('e_mode'), [ID_E_DECOUPLED_AFTERNOON, ID_E_DECOUPLED_BOTH]) && $this->pp('e2_teams') > 0) {
                    Log::debug('Explore decoupled afternoon group');
                    $this->explore->briefings($this->eTime->current(), 2);
                    $this->explore->judging(2);
                    $this->explore->deliberationsAndAwards(2);
                }

            } else {
                Log::debug('Explore no decoupled groups');
            }

        } else {
         
            Log::info('No FLL Challenge teams, skipping generation');

            // ***********************************************************************************
            // FLL Explore without FLL Challenge
            // ***********************************************************************************

            Log::debug('FLL Explore only (no Challenge present)');

            $explore = new ExploreGenerator($this->writer, $this->eTime, $this->rTime, $this->planId);
            $this->explore->decoupled($this->pp('g_date'));

        }
    }


    public function openingsAndBriefings(bool $explore = false): void
    {
        $startOpening = clone $this->cTime; 

        if ($explore) {

            $this->cTime->setTime($this->pp('g_start_opening'));

            $this->writer->withGroup('g_opening', function () {
                $this->writer->insertActivity('g_opening', $this->cTime, $this->pp('g_duration_opening'));
            });

            $this->jTime->addMinutes($this->pp('g_duration_opening'));
            $this->rTime->addMinutes($this->pp('g_duration_opening'));

            Log::info('Explore integrated morning: teams=' . $this->pp('e1_teams') . ', lanes=' . $this->pp('e1_lanes') . ', rounds=' . $this->pp('e1_rounds'));

        } else {

            $this->cTime->setTime($this->pp('c_start_opening'));

            $this->writer->withGroup('c_opening', function () {
                $this->writer->insertActivity('c_opening', $this->cTime, $this->pp('c_duration_opening'));
            });

            $this->jTime->addMinutes($this->pp('c_duration_opening'));
            $this->rTime->addMinutes($this->pp('c_duration_opening'));

            Log::debug('Explore no integrated morning batch');
        }

        $this->briefings($startOpening->current());

    }

    public function briefings(\DateTime $t): void
    {
        
        $this->writer->withGroup('c_coach_briefing', function () use ($t) {
            $start = (clone $t)->modify('-' . ($this->pp('c_duration_briefing') + $this->pp('c_ready_opening')) . ' minutes');
            $cursor = new TimeCursor($start);
            $this->writer->insertActivity('c_coach_briefing', $cursor, $this->pp('c_duration_briefing'));
        });

        $this->writer->withGroup('c_judge_briefing', function () use ($t) {
            if (!$this->pp('j_briefing_after_opening')) {
                $start = (clone $t)->modify('-' . ($this->pp('j_duration_briefing') + $this->pp('c_ready_opening')) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('c_judge_briefing', $cursor, $this->pp('j_duration_briefing'));
            } else {
                $this->jTime->addMinutes($this->pp('j_ready_briefing'));
                $this->writer->insertActivity('c_judge_briefing', $this->jTime, $this->pp('j_duration_briefing'));
                $this->jTime->addMinutes($this->pp('j_duration_briefing'));
                $this->jTime->addMinutes($this->pp('j_ready_action'));

            }
        });

        $this->writer->withGroup('r_referee_briefing', function () use ($t) {
            if (!$this->pp('r_briefing_after_opening')) {
                $start = (clone $t)->modify('-' . ($this->pp('r_duration_briefing') + $this->pp('c_ready_opening')) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('r_referee_briefing', $cursor, $this->pp('r_duration_briefing'));
            } else {
                $this->rTime->addMinutes($this->pp('r_ready_briefing'));
                $this->writer->insertActivity('r_referee_briefing', $this->rTime, $this->pp('r_duration_briefing'));
                $this->rTime->addMinutes($this->pp('r_duration_briefing'));
                $this->rTime->addMinutes($this->pp('r_ready_action'));
            }
        });

    }



    public function challenge(): void
    {
        Log::info('=== FLL Challenge generation start ===');

        // -----------------------------------------------------------------------------------
        // FLL Challenge
        // -----------------------------------------------------------------------------------
        // Robot Game and Judging run parallel in sync

        // Create the robot game match plan
        $this->matchPlan = new MatchPlan($this->writer);
        $this->matchPlan->create();

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Put the judging / robot game schedule together
        // -----------------------------------------------------------------------------------

        // Current time is the earliest available time.
        $jTimeEarliest = $this->jTime->copy(); // In block 1 judging starts immediately. No need to compare with robot game.

        $cBlock = 0;
        $rStartShift = 0;
        $jT = 0; // first team index for this block

        // Time for judging = how long will a team be away to judging and thus not available for robot game.
        $jT4J = $this->pp('j_duration_with_team') + $this->pp('c_duration_transfer');

        // Create the blocks of judging with robot game aligned
        for ($cBlock = 1; $cBlock <= $this->pp('j_rounds'); $cBlock++) {
            Log::debug("Challenge block {$cBlock} of {$this->pp('j_rounds')}");

            // -----------------------------------------------------------------------------------
            // Adjust timing between judging and robot game
            // -----------------------------------------------------------------------------------

            // duration of one match: test round or normal
            $rDuration = ($cBlock == 1)
                ? $this->pp('r_duration_test_match')   // Test round
                : $this->pp('r_duration_match');

            // Key concept 1:
            // teams first in robot game go to judging in NEXT round
            // available for judging = time from start of robot game round to being in front of judges' room
            // Calculate forward from start of the round:
            // 1 or 2 lanes = 1 match
            // 3 or 4 lanes = 2 matches
            // 5 or 6 lanes = 3 matches

            // Delay judging if needed
            if ($this->jTime->diffInMinutes($jTimeEarliest) < 0) {
                Log::debug("Judging delayed from {$this->jTime->format()} to {$jTimeEarliest->format()}");
                $this->jTime = $jTimeEarliest->copy();
            }

            // Key concept 2:
            // teams at judging are last in CURRENT robot game round
            // number of matches before teams must be back from judging
            if ($cBlock == $this->pp('j_rounds') && ($this->pp('c_teams') % $this->pp('j_lanes')) !== 0) {
                // not all lanes filled in last round of judging
                $rMB = $this->pp('r_matches_per_round') - ceil(($this->pp('c_teams') % $this->pp('j_lanes')) / 2);
            } else {
                $rMB = $this->pp('r_matches_per_round') - ceil($this->pp('j_lanes') / 2);
            }

            // If asymmetrical match plan, one empty match is added into the test round.
            if ($cBlock == 1 && $this->pp('r_asym')) {
                $rMB++;
            }

            // Calculate time to START of match
            if ($this->pp('r_tables') == 2) {
                // matches START in sequence
                $rT2M = ($rMB - 1) * $rDuration;
            } else {
                // matches START alternating with respective delay between STARTs
                if ($rMB % 2 === 0) {
                    $rT2M = ($rMB / 2 - 1) * $rDuration + $this->pp('r_duration_next_start');
                } else {
                    $rT2M = (($rMB - 1) / 2) * $rDuration;
                }
            }

            // Compare time away for judging and expectations from robot game
            // Factor in the current difference between robot game and judging
            $rStartShift = $jT4J - $rT2M - $this->rTime->diffInMinutes($this->jTime);

            // Delay robot game if needed
            if ($rStartShift > 0) {
                $this->rTime->addMinutes($rStartShift);
            }

            // -----------------------------------------------------------------------------------
            // Calculate a4j for concept 1
            // -----------------------------------------------------------------------------------

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

            // Store this as time object
            $jTimeEarliest = $this->rTime->copy()->addMinutes($rA4J);

            // -----------------------------------------------------------------------------------
            // Now we are ready to create activities for robot game and then judging
            // -----------------------------------------------------------------------------------

            // judging including breaks
            $this->challenge->judgingOneRound($cBlock, $jT);

            // First team to start with in next block
            $jT += $this->pp('j_lanes');

            // Robot Game rounds depending on block and config
            switch ($cBlock) {
                case 1:
                    // First judging round runs parallel to RG test round, regardless of j_rounds
                    $this->matchPlan->insertOneRound(0, $this->rTime);
                    break;
                case 2:
                    if ($this->pp('j_rounds') == 4) {
                        $this->matchPlan->insertOneRound(1, $this->rTime);
                    }
                    break;
                case 3:
                    if ($this->pp('j_rounds') == 4) {
                        $this->matchPlan->insertOneRound(2, $this->rTime);
                    } else {
                        $this->matchPlan->insertOneRound(1, $this->rTime);
                    }
                    break;
                case 4:
                    if ($this->pp('j_rounds') == 4) {
                        $this->matchPlan->insertOneRound(3, $this->rTime);
                    } else {
                        $this->matchPlan->insertOneRound(2, $this->rTime);
                    }
                    break;
                case 5:
                    $this->matchPlan->insertOneRound(3, $this->rTime);
                    break;
                case 6:
                    // No robot game left
                    break;
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
                if ($this->jTime->diffInMinutes($this->rTime) < 0) {
                    $this->rTime = $this->jTime->copy();
                } else {
                    $this->jTime = $this->rTime->copy();
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
        $this->cTime = $this->jTime->copy()->addMinutes(-$this->pp('j_duration_scoring'));

        // If RG is later, their time wins
        if ($this->rTime->current() > $this->cTime->current()) {
            $this->cTime = $this->rTime->copy();
        }

        Log::info('=== FLL Challenge generation complete ===');

                // -----------------------------------------------------------------------------------
        // FLL Challenge: Deliberations
        // -----------------------------------------------------------------------------------

        // Move to judges main room
        $this->jTime->addMinutes($this->pp('j_ready_deliberations'));

        // Deliberation
        $this->writer->withGroup('c_deliberations', function () {
            $this->writer->insertActivity('c_deliberations', $this->jTime, $this->pp('j_duration_deliberations'));
        });
        $this->jTime->addMinutes($this->pp('j_duration_deliberations'));
    }


    public function robotGameFinals(): void
    {
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
        $this->rTime = $this->cTime->copy();

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
        //
        // Hinweis: die konkreten Implementierungen dieser Runden liegen im MatchPlan.
        // Wir rufen hier nur die passenden Methoden, analog zu r_final_round(N).

        if ($this->pp('g_finale') && $this->pp('c_teams') >= 16) {
            // The DACH Finale is the only event running the round of best 16
            if (method_exists($this->matchPlan, 'finalRound')) {
                $this->matchPlan->insertFinalRound(16, $this->rTime);
            } else {
                // Fallback: gleiche Semantik wie r_insert_one_round für Finals (TODO: implement in MatchPlan)
                $this->matchPlan->insertOneRound(16, $this->rTime);
            }
        }

        // Organizer can decide not to run round of best 8
        if (($this->pp('g_finale') || $this->pp('r_quarter_final')) && $this->pp('c_teams') >= 8) {
            if (method_exists($this->matchPlan, 'finalRound')) {
                $this->matchPlan->insertFinalRound(8, $this->rTime);
            } else {
                $this->matchPlan->insertOneRound(8, $this->rTime);
            }
        }

        // Semi finale is a must
        if (method_exists($this->matchPlan, 'finalRound')) {
            $this->matchPlan->insertFinalRound(4, $this->rTime);
        } else {
            $this->matchPlan->insertOneRound(4, $this->rTime);
        }

        // Final matches
        if (method_exists($this->matchPlan, 'finalRound')) {
            $this->matchPlan->insertFinalRound(2, $this->rTime);
        } else {
            $this->matchPlan->insertOneRound(2, $this->rTime);
        }

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------
        if ($this->pp('c_presentations') > 0 && $this->pp('c_presentations_last')) {
            // Research presentations on stage
            $this->rTime->addMinutes($this->pp('c_ready_presentations'));

            $this->presentations();
        }

  
        // back to only one action a time
        $this->cTime = $this->rTime->copy();

        // FLL Challenge
        // Deliberations might have taken longer, which is unlikely
        if ($this->jTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
            $this->cTime = $this->jTime->copy();
        }

     
    }
    
    public function presentations(): void
    {
        $duration = pp('c_presentations') * pp('c_duration_presentation') + 5;

        $this->writer->withGroup('c_presentations', function () use ($duration) {
            $this->writer->insertActivity('c_presentations', $this->rTime, $duration);
        });

        $this->rTime->addMinutes($duration);

        $insertPoint = pp('c_presentations_last')
            ? 'c_ready_awards'
            : 'c_ready_presentations';

        $this->writer->insertPoint('presentations', pp($insertPoint), $this->rTime);
    }


    public function awards( bool $explore = false): void
    {

        if ($explore) {

            Log::debug('Awards joint');

            $this->writer->withGroup('g_awards', function () {
                $this->writer->insertActivity('g_awards', $this->cTime, $this->pp('g_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('g_duration_awards'));

        } else {

            Log::debug('Awards Challenge only');

            $this->writer->withGroup('c_awards', function () {
                $this->writer->insertActivity('c_awards', $this->cTime, $this->pp('c_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('c_duration_awards'));
        }
        
    }

}