<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;

use App\Core\ActivityWriter;
use App\Core\ChallengeGenerator;
use App\Core\ExploreGenerator;
use App\Core\MatchPlan;
use App\Core\TimeCursor;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;

class PlanGeneratorCore
{
    private int $planId;
    private ActivityWriter $writer;
    private PlanParameter $params;

    private TimeCursor $cTime;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $eTime;
    private TimeCursor $lcTime;

    private ChallengeGenerator $challenge;
    private ExploreGenerator $explore;
    private MatchPlan $matchPlan;

    private int $cDay = 0; // Aktueller Event-Tag (1 = erster, 2 = zweiter, …)

    use UsesPlanParameter;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
        $this->writer = new ActivityWriter($planId);
        $this->params = PlanParameter::load($planId);
    }

    // ***********************************************************************************
    // Parameter naming convention
    // ***********************************************************************************
    // "snake_case" is used
    // g_ global / generic
    // c_ FLL Challenge
    // j_ FLL Challenge judging
    // r_ FLL Challenge robot game
    // e_ FLL Explore
    // e1_ FLL Explore first group (morning)
    // e2_ FLL Explore second group (afternoon)
    // f_ Finale
    // lc_ Live Challenge

    public function generate(): void
    {
        Log::info("PlanGeneratorCore: Start generation for plan {$this->planId}");

        $this->initialize();
        
        $this->prepareGenerators();
        
        try {
                $this->main();
            } catch (\Throwable $e) {
                Log::error("Plan generation failed: {$e->getMessage()}", ['plan_id' => $this->planId]);
                throw $e;
            }
        
        Log::info("PlanGeneratorCore: Finished generation for plan {$this->planId}");

        // -----------------------------------------------------------------------------------
        // Add all free blocks.
        // Timing does not matter, because these are parallel to other activities.
        // -----------------------------------------------------------------------------------

        $this->writer->insertFreeActivities();
    }

    private function prepareGenerators(): void
    {
        // MatchPlan hängt nur von Writer ab
        $this->matchPlan = new MatchPlan($this->writer);

        // Challenge und Explore bekommen gemeinsame Parameter und TimeCursor-Referenzen
        $this->challenge = new ChallengeGenerator(
            $this->writer,
            $this->cTime,
            $this->jTime,
            $this->rTime
        );
        $this->challenge->setParams($this->params);

        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->eTime,
            $this->rTime
        );
        $this->explore->setParams($this->params);
    }

    public function initialize(): void
    {
        // ***********************************************************************************
        // Get all data about the event and parameter values set by the organizer
        // ***********************************************************************************

        Log::debug("PlanGeneratorCore: initialize plan={$this->planId}");

        Log::debug('Plan parameters read');

        // Derrived values that are calculated from the parameters
        // Treat them like any other parameter

        if ($this->pp('c_teams') > 0) {
            // Number of jury rounds in the schedule: Minimum 4 for 3x Robot Game + Test Round. Maximum 6 for fully utilized jury
            $this->params->add('j_rounds', (int)ceil($this->pp('c_teams') / $this->pp('j_lanes')), 'integer');

            // need one match per two teams
            $this->params->add('r_matches_per_round', (int)ceil($this->pp('c_teams') / 2), 'integer');

            // uneven number of teams --> "need a volunteer without scoring"
            $this->params->add(
                'r_need_volunteer',
                $this->pp('r_matches_per_round') != ($this->pp('c_teams') / 2),
                'boolean'
            );

            // 4 tables, but not multiple of 4 --> table 3/4 ends before 1/2
            $this->params->add(
                'r_asym',
                $this->pp('r_tables') == 4
                && (
                    ($this->pp('c_teams') % 4 == 1)
                    || ($this->pp('c_teams') % 4 == 2)
                ),
                'boolean'
            );
        }

        if ($this->pp('e1_teams') > 0) {
            // Number of jury rounds in the schedule:
            $this->params->add(
                'e1_rounds',
                (int)ceil($this->pp('e1_teams') / $this->pp('e1_lanes')),
                'integer'
            );
        }

        if ($this->pp('e2_teams') > 0) {
            // Number of jury rounds in the schedule:
            $this->params->add(
                'e2_rounds',
                (int)ceil($this->pp('e2_teams') / $this->pp('e2_lanes')),
                'integer'
            );
        }


    }

    public function main()
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
                $explore = new ExploreGenerator($this->writer, $this->eTime, $this->rTime);

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

            // -----------------------------------------------------------------------------------
            // Finale has an extra day for Live Challenge and RG test rounds
            // -----------------------------------------------------------------------------------

            if ($this->pp('g_finale')) {

                // Only for the D-A-CH final we run the Live Challenge
                // This is done on the day before the regular event
                // Teams get extra time with the same judges they meet during the regular event
                // In parallel test rounds for robot game are run

                Log::debug('Finale mode active → starting Live Challenge day');

                // Der Live-Challenge-Generator kann später eine eigene Klasse bekommen, z. B. LiveChallengeGenerator
                // Bis dahin Platzhalter:
                if (method_exists($this, 'generateLiveChallenge')) {
                    $this->generateLiveChallenge();
                } else {
                    Log::warning('Live Challenge generation not yet implemented (TODO)');
                }
            }

        } else {
         
            Log::info('No FLL Challenge teams, skipping generation');

            // ***********************************************************************************
            // FLL Explore without FLL Challenge
            // ***********************************************************************************

            Log::debug('FLL Explore only (no Challenge present)');

            $explore = new ExploreGenerator($this->writer, $this->eTime, $this->rTime);
            $this->explore->decoupled($this->pp('g_date'));

        }
    }


    public function openings(): void
    {
        // -----------------------------------------------------------------------------------
        // Challenge opening alone or joint with Explore 
        // -----------------------------------------------------------------------------------

        // Save time to schedule briefings before opening
        $briefingStart = clone $this->cTime; 

        if ($this->pp('e_mode') == ID_E_MORNING) {
            // joint opening  

            Log::debug('Opening joint');

            $this->writer->withGroup('g_opening', function () {
                $this->writer->insertActivity('g_opening', $this->cTime, $this->pp('g_duration_opening'));
            });

            // All domains move forward together
            $this->jTime->addMinutes($this->pp('g_duration_opening'));
            $this->rTime->addMinutes($this->pp('g_duration_opening'));
            $this->eTime->addMinutes($this->pp('g_duration_opening'));
        } else {
            // FLL Challenge only during the morning

            Log::debug('Opening Challenge only');

            $this->writer->withGroup('c_opening', function () {
                $this->writer->insertActivity('c_opening', $this->cTime, $this->pp('c_duration_opening'));
            });

            $this->jTime->addMinutes($this->pp('c_duration_opening'));
            $this->rTime->addMinutes($this->pp('c_duration_opening'));
        }

        // -----------------------------------------------------------------------------------
        // Briefings before or after opening
        // -----------------------------------------------------------------------------------

        // Add briefings
        $this->challengebriefings($briefingStart->current(), $this->cDay);

        // -----------------------------------------------------------------------------------
        // FLL Explore integrated during the morning 
        // -----------------------------------------------------------------------------------
        // Start with FLL Explore, because awards ceremony is between FLL Challenge robot game rounds
        // Therefore, FLL Explore timing needs to be calculate first!
        // Skip all, if there are not FLL Explore teams in the morning

        if ($this->pp('e_mode') == ID_E_MORNING) {

            // Add briefings
            $this->explore->briefings($briefingStart->current(), 1);

            Log::debug('Explore morning');
            Log::debug('Explore morning: teams=' . $this->pp('e1_teams') . ', lanes=' . $this->pp('e1_lanes') . ', rounds=' . $this->pp('e1_rounds'));

            // (Supported plan checks removed)

            // Full FLL Explore schedule for group 1
            $this->explore->judging(1);

            // Buffer before all judges meet for deliberations
            $this->eTime->addMinutes($this->pp('e_ready_deliberations'));

            // Deliberations
            $this->writer->withGroup('e_deliberations', function () {
                $this->writer->insertActivity('e_deliberations', $this->eTime, $this->pp('e1_duration_deliberations'));
            });

            $this->eTime->addMinutes($this->pp('e1_duration_deliberations'));

            // Awards for FLL Explore is next:
            // This would be the earliest time for FLL Explore awards
            // However, robot game may not have finished yet.
            // Thus the timing is determined further down 

        } else {
            Log::debug('Explore no morning batch');
        }
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
    }


    public function awardsAndPostRounds(): void
    {
        // -----------------------------------------------------------------------------------
        // FLL Challenge: Everything after judging / robot game rounds
        // -----------------------------------------------------------------------------------
        // 1 Judges go to deliberation
        // 2 Selected research on main stage
        // 3 followed by robot game finals
        // 4 awards
        //
        // 2 and 3 may be flipped

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

        // -----------------------------------------------------------------------------------
        // Special for D-A-CH finale Siegen 2025: Move the next to another day. TODO
        // -----------------------------------------------------------------------------------
        if ($this->pp('g_finale') && $this->pp('g_days') == 3) {

            // Debriefing for referees
            $this->rTime->addMinutes($this->pp('r_duration_break'));
            $this->writer->withGroup('r_referee_debriefing', function () {
                // alle 0er-Params aus der alten Welt sind hier nicht nötig
                $this->writer->insertActivity('r_referee_debriefing', $this->rTime, $this->pp('r_duration_debriefing'));
            });

            // Move to next day
            [$h3, $m3] = explode(':', $this->pp('f_start_opening_day_3'));
            $this->cTime->current()->setTime((int)$h3, (int)$m3);
            $this->cTime->addMinutes(24 * 60); // +1 day

            // Additional short referee briefing (morning day 3)
            $t = $this->cTime->copy();
            $t->addMinutes(-1 * ($this->pp('r_duration_briefing_2') + $this->pp('c_ready_opening')));

            $this->writer->withGroup('r_referee_briefing', function () use ($t) {
                $this->writer->insertActivity('r_referee_briefing', $t, $this->pp('r_duration_briefing_2'));
            });

            // Small opening day 3
            $this->writer->withGroup('c_opening_day_3', function () {
                $this->writer->insertActivity('c_opening_day_3', $this->cTime, $this->pp('f_duration_opening_day_3'));
            });
            $this->cTime->addMinutes($this->pp('f_duration_opening_day_3'));

            // Buffer between opening and first action for teams and judges
            $this->cTime->addMinutes($this->pp('f_ready_action_day_3'));
        }

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
            $this->challenge->presentations();
            // ChallengeGenerator verschiebt $rTime intern bereits um die Dauer
        }

        // TODEL
        // Additional 5 minutes to show who advances and for those teams to get ready
        // (war im Legacy auskommentiert, übernehmen wir kommentiert)
        // $this->rTime->addMinutes($this->pp('r_duration_results'));

        // -----------------------------------------------------------------------------------
        /// Robot-game final rounds
        // -----------------------------------------------------------------------------------
        //
        // Hinweis: die konkreten Implementierungen dieser Runden liegen im MatchPlan.
        // Wir rufen hier nur die passenden Methoden, analog zu r_final_round(N).

        if ($this->pp('g_finale') && $this->pp('c_teams') >= 16) {
            // The DACH Finale is the only event running the round of best 16
            if (method_exists($this->matchPlan, 'finalRound')) {
                $this->matchPlan->finalRound(16, $this->rTime);
            } else {
                // Fallback: gleiche Semantik wie r_insert_one_round für Finals (TODO: implement in MatchPlan)
                $this->matchPlan->insertOneRound(16, $this->rTime);
            }
        }

        // Organizer can decide not to run round of best 8
        if (($this->pp('g_finale') || $this->pp('r_quarter_final')) && $this->pp('c_teams') >= 8) {
            if (method_exists($this->matchPlan, 'finalRound')) {
                $this->matchPlan->finalRound(8, $this->rTime);
            } else {
                $this->matchPlan->insertOneRound(8, $this->rTime);
            }
        }

        // Semi finale is a must
        if (method_exists($this->matchPlan, 'finalRound')) {
            $this->matchPlan->finalRound(4, $this->rTime);
        } else {
            $this->matchPlan->insertOneRound(4, $this->rTime);
        }

        // Final matches
        if (method_exists($this->matchPlan, 'finalRound')) {
            $this->matchPlan->finalRound(2, $this->rTime);
        } else {
            $this->matchPlan->insertOneRound(2, $this->rTime);
        }

        // -----------------------------------------------------------------------------------
        // FLL Challenge: Research presentations on stage
        // -----------------------------------------------------------------------------------
        if ($this->pp('c_presentations') > 0 && $this->pp('c_presentations_last')) {
            // Research presentations on stage
            $this->rTime->addMinutes($this->pp('c_ready_presentations'));

            $this->challenge->presentations();
        }

        // -----------------------------------------------------------------------------------
        // Awards
        // -----------------------------------------------------------------------------------

        // back to only one action a time
        $this->cTime = $this->rTime->copy();

        // FLL Challenge
        // Deliberations might have taken longer, which is unlikely
        if ($this->jTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
            $this->cTime = $this->jTime->copy();
        }

        // FLL Explore
        // Deliberations might have taken longer. Which is rather theoretical ...
        if ($this->pp('e_mode') == ID_E_AFTERNOON
            && $this->eTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
            $this->cTime = $this->eTime->copy();
        }

        // Awards
        if ($this->pp('e_mode') == ID_E_AFTERNOON) {

            // Joint with Explore
            Log::debug('Awards joint');

            $this->writer->withGroup('g_awards', function () {
                $this->writer->insertActivity('g_awards', $this->cTime, $this->pp('g_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('g_duration_awards'));

        } else {

            // Only Challenge
            Log::debug('Awards Challenge only');

            $this->writer->withGroup('c_awards', function () {
                $this->writer->insertActivity('c_awards', $this->cTime, $this->pp('c_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('c_duration_awards'));
        }
    }





}