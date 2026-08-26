<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Support\MatchPlanSpec;
use App\Enums\ExploreMode;
use App\Enums\FirstProgram;


class ChallengeGenerator implements ChallengeShapedLead
{
    private ActivityWriter $writer;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $cTime;
    private RobotGameGenerator $robotGame;

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

        $jRounds = max(4, (int) ceil($cTeams / max(1, $jLanes)));
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
            // Determine lunch round based on c_lunch_break_early parameter
            $isLunchRound = false;
            if ($this->pp('c_lunch_break_early')) {
                // Early lunch: after block 1 (4 rounds) or block 2 (5+ rounds)
                $isLunchRound = (($this->pp('j_rounds') == 4 && $cBlock == 1) ||
                                ($this->pp('j_rounds') > 4 && $cBlock == 2));
            } else {
                // Normal lunch: after block 2 (4 rounds) or block 3 (5+ rounds)
                $isLunchRound = (($this->pp('j_rounds') == 4 && $cBlock == 2) ||
                                ($this->pp('j_rounds') > 4 && $cBlock == 3));
            }
            
            if ($isLunchRound) {
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

            } else {

                $this->cTime->setTime($this->pp('c_start_opening'));
                $this->jTime->set($this->cTime->current());
                $this->rTime->set($this->cTime->current());

                $this->writer->withGroup('c_opening', function () {
                    $this->writer->insertActivity('c_opening', $this->cTime, $this->pp('c_duration_opening'));
                });

                $this->jTime->addMinutes($this->pp('c_duration_opening'));
                $this->rTime->addMinutes($this->pp('c_duration_opening'));
            }

            $this->briefings($this->cTime->current());

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in openings and briefings', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Challenge-Eröffnung und Briefings (Explore: " . ($explore ? 'aktiv' : 'inaktiv') . "): {$e->getMessage()}", 0, $e);
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



    public function main(bool $explore = false, ?callable $afterRG1Callback = null): void
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
            // Match list (who vs whom) is Challenge-owned; the writer only places it on rTime.
            $matchPlan = (new MatchPlanBuilder)->build(
                MatchPlanSpec::for(FirstProgram::CHALLENGE, $this->params)
            );
            $this->robotGame = new RobotGameGenerator(
                $this->writer,
                $this->params,
                $this->rTime,
                $this->integratedExplore,
                $matchPlan
            );

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
                $this->alignJudgingWithRobotGame($cBlock, $jTimeEarliest, $jT4J);

                // -----------------------------------------------------------------------------------
                // Now we are ready to create activities for robot game and then judging
                // -----------------------------------------------------------------------------------

                // judging including breaks
                $this->judgingOneRound($cBlock, $jT);

                // First team to start with in next block
                $jT += $this->pp('j_lanes');

                // Robot Game rounds depending on block and config
                $this->insertRobotGameRoundForBlock($cBlock, $afterRG1Callback);

                // -----------------------------------------------------------------------------------
                // If a hard lunch break is set, do it here
                // -----------------------------------------------------------------------------------
                $this->maybeInsertHardLunch($cBlock);
            }

            // -----------------------------------------------------------------------------------
            // Synchronize after judging and robot game
            // -----------------------------------------------------------------------------------
            $this->syncCeremonyTimeAfterMain();

            // -----------------------------------------------------------------------------------
            // FLL Challenge: Deliberations
            // -----------------------------------------------------------------------------------
            $this->insertDeliberations();

        } catch (\Throwable $e) {
            Log::error('ChallengeGenerator: Error in main challenge generation', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Challenge-Hauptaktivitäten (Explore: " . ($explore ? 'aktiv' : 'inaktiv') . "): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delay judging and/or robot game so teams are not in two places.
     * Updates $jTimeEarliest for the next block. Formulas unchanged.
     */
    private function alignJudgingWithRobotGame(int $cBlock, TimeCursor &$jTimeEarliest, mixed $jT4J): void
    {
        Log::debug("Before: cBlock: {$cBlock}, jTime: {$this->jTime->current()->format('H:i')}, rTime: {$this->rTime->current()->format('H:i')}, jTimeEarliest: {$jTimeEarliest->current()->format('H:i')}");

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
            // Last round has partial lanes: protect as many matches as we have teams in that round
            // (e.g. 14 teams, 4 lanes → 2 teams in round 4 → ensure matches 4 and 5 start after judging+transfer)
            $teamsInLastRound = $this->pp('c_teams') % $this->pp('j_lanes');
            $rMB = max(0, $this->pp('r_matches_per_round') - $teamsInLastRound);
        } else {
            $rMB = $this->pp('r_matches_per_round') - ceil($this->pp('j_lanes') / 2);
        }

        // If asymmetrical match plan, one empty match is added into the test round.
        if ($cBlock == 1 && $this->pp('r_asym') && $this->pp("j_rounds") != 4) {
            $rMB++;
        }

        // When the NEXT judging round has no teams (e.g. 9 teams, 3 lanes → round 4 empty),
        // teams at judging in THIS round can appear in any RG match (rotation). Ensure the
        // whole robot game round starts after judging+transfer so every team gets >= transfer.
        if ($cBlock < $this->pp('j_rounds') && $this->pp('c_teams') <= $cBlock * $this->pp('j_lanes')) {
            $rMB = 0;
        }

        // Calculate time to START of protected match (4 tables: pair stagger ns / D-ns grid).
        if ($this->pp('r_tables') == 2) {
            // matches START in sequence
            $rT2M = $rMB * $rDuration;
        } else {
            // start(m) = floor((m-1)/2)*D + ((m-1)%2)*ns — same as RobotGameGenerator
            if ($rMB % 2 === 0) {
                $rT2M = $rMB       / 2 * $rDuration;

            } else {
                $rT2M = ($rMB - 1) / 2 * $rDuration + $this->pp('r_duration_next_start');
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

        if ($this->pp('j_rounds') > 4 && $cBlock == 2) {

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

        if ($this->pp('g_finale') && $cBlock == 4) {
            // Special case finale: Judging round 5 can start as soon as they are ready. No need to wait for robot game.
            $jTimeEarliest = clone $this->jTime;
        }
        else
        {
            $jTimeEarliest = clone $this->rTime;
            $jTimeEarliest->addMinutes($rA4J);
        }

        Log::debug("After: cBlock: {$cBlock}, jTime: {$this->jTime->current()->format('H:i')}, rTime: {$this->rTime->current()->format('H:i')}, jTimeEarliest: {$jTimeEarliest->current()->format('H:i')}");
    }

    /**
     * Write the robot-game round that belongs with this judging block.
     * After RG1 (non-finale), run the Explore morning-awards handoff once.
     */
    private function insertRobotGameRoundForBlock(int $cBlock, ?callable $afterRG1Callback): void
    {
        // Robot Game rounds depending on block and config
        if ($this->pp('g_finale')) {
            // Finale Day 2: TR already on Day 1, start with regular rounds
            switch ($cBlock) {
                case 1:
                    $this->robotGame->insertOneRound(1);  // R1
                    break;
                case 2:
                    // No robot game
                    break;
                case 3:
                    $this->robotGame->insertOneRound(2);  // R2
                    break;
                case 4:
                    $this->robotGame->insertOneRound(3);  // R3
                    break;
                case 5:
                    // No robot game (Finals or Awards will be added separately)
                    break;
            }

            return;
        }

        $insertedRg1 = false;

        // Normal event mapping
        switch ($cBlock) {
            case 1:
                // First judging round runs parallel to RG test round, regardless of j_rounds
                $this->robotGame->insertOneRound(0);
                break;
            case 2:
                if ($this->pp('j_rounds') == 4) {
                    $this->robotGame->insertOneRound(1);
                    $insertedRg1 = true;
                }
                break;
            case 3:
                if ($this->pp('j_rounds') == 4) {
                    $this->robotGame->insertOneRound(2);
                } else {
                    $this->robotGame->insertOneRound(1);
                    $insertedRg1 = true;
                }
                break;
            case 4:
                if ($this->pp('j_rounds') == 4) {
                    $this->robotGame->insertOneRound(3);
                } else {
                    $this->robotGame->insertOneRound(2);
                }
                break;
            case 5:
                $this->robotGame->insertOneRound(3);
                break;
            case 6:
                // No robot game left
                break;
        }

        if ($insertedRg1) {
            $this->maybeRunAfterRG1Handoff($afterRG1Callback);
        }
    }

    private function maybeRunAfterRG1Handoff(?callable $afterRG1Callback): void
    {
        // For INTEGRATED_MORNING: insert awards and adjust rTime after RG1, before RG2
        if ($afterRG1Callback !== null && $this->exploreMode() == ExploreMode::INTEGRATED_MORNING->value) {
            $afterRG1Callback($this->rTime);
        }
    }

    /**
     * Hard lunch after the configured judging block, if c_duration_lunch_break > 0.
     */
    private function maybeInsertHardLunch(int $cBlock): void
    {
        // -----------------------------------------------------------------------------------
        // If a hard lunch break is set, do it here
        // -----------------------------------------------------------------------------------
        // Determine lunch round based on c_lunch_break_early parameter
        $isLunchRound = false;
        if ($this->pp('c_lunch_break_early') && !$this->pp('g_finale')) {
            // Early lunch: after block 1 (4 rounds) or block 2 (5+ rounds) - not applicable for finale
            $isLunchRound = (($this->pp('j_rounds') == 4 && $cBlock == 1) ||
                            ($this->pp('j_rounds') > 4 && $cBlock == 2));
        } else {
            // Normal lunch: after block 2 (4 rounds) or block 3 (5+ rounds)
            $isLunchRound = (($this->pp('j_rounds') == 4 && $cBlock == 2) ||
                            ($this->pp('j_rounds') > 4 && $cBlock == 3));
        }

        if ($isLunchRound && $this->pp('c_duration_lunch_break') > 0) {
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

    private function syncCeremonyTimeAfterMain(): void
    {
        // -----------------------------------------------------------------------------------
        // Synchronize after judging and robot game
        // -----------------------------------------------------------------------------------

        $this->cTime->set($this->jTime->current());
        $this->cTime->addMinutes(-$this->pp('j_duration_scoring'));

        // If RG is later, their time wins
        if ($this->rTime->current() > $this->cTime->current()) {
            $this->cTime->set($this->rTime->current());
        }
    }

    private function insertDeliberations(): void
    {
        // -----------------------------------------------------------------------------------
        // FLL Challenge: Deliberations
        // -----------------------------------------------------------------------------------

        // Move to judges main room
        $this->jTime->addMinutes($this->pp('j_ready_deliberations'));

        // If j_deliberations_flex is 0 (sync): postpone start of deliberations to end of RG round 3 when earlier; 1 = no sync
        if (!$this->pp('j_deliberations_flex') && $this->jTime->current() < $this->rTime->current()) {
            $this->jTime->set($this->rTime->current());
        }

        // Deliberation
        $this->writer->withGroup('j_deliberations', function () {
            $this->writer->insertActivity('j_deliberations', $this->jTime, $this->pp('j_duration_deliberations'));
        });
        $this->jTime->addMinutes($this->pp('j_duration_deliberations'));
    }


    /** After RG3: results pause, then Core walks the Nachmittag list. */
    public function beginAfternoon(): void
    {
        $this->rTime->set($this->cTime->current());
        $this->rTime->addMinutes($this->pp('r_duration_results'));
    }

    public function insertFinalRound(int $teamCount): void
    {
        $this->robotGame->insertFinalRound($teamCount, true);
        if ($teamCount !== 2) {
            $this->rTime->addMinutes($this->pp('r_duration_results'));
        }
    }

    public function presentations(): void
    {
        $this->rTime->addMinutes($this->pp('c_ready_presentations'));

        $duration = $this->pp('c_presentations') * $this->pp('c_duration_presentation') + 5;

        $this->writer->withGroup('c_presentations', function () use ($duration) {
            $this->writer->insertActivity('c_presentations', $this->rTime, $duration);
        });

        $this->rTime->addMinutes($duration);
        $this->rTime->addMinutes($this->pp('c_ready_presentations'));
    }

    /** Ready-for-awards pause; ceremony clock follows robot game, then judging if later. */
    public function endAfternoon(): void
    {
        $this->rTime->addMinutes($this->pp('c_ready_awards'));
        $this->cTime->set($this->rTime->current());

        if ($this->jTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
            $this->cTime->set($this->jTime->current());
        }
    }


    public function awards( bool $explore = false): void
    {
        try {
            if ($explore) {

            if ($this->exploreMode() == ExploreMode::HYBRID_BOTH->value) {
                // Calculate backwards from c_time to determine when Explore group 2 should start
                // Formula: c_time - e_ready_awards - e_ready_deliberations - e2_duration_deliberations
                //          - (e2_rounds * (e_duration_with_team + e_duration_scoring)) - ((e2_rounds - 1) * e_duration_break)
                //          - e_ready_action - e2_duration_opening
                
                $exploreStartTime = clone $this->cTime;
                
                $exploreStartTime->subMinutes($this->pp('e_ready_awards'));
                $exploreStartTime->subMinutes($this->pp('e2_duration_deliberations'));
                $exploreStartTime->subMinutes($this->pp('e_ready_deliberations'));
                
                $e2Rounds = $this->pp('e2_rounds');
                // Each judging round consists of with_team + scoring
                $durationPerRound = $this->pp('e_duration_with_team') + $this->pp('e_duration_scoring');
                $exploreStartTime->subMinutes($e2Rounds * $durationPerRound);
                // Breaks between rounds (only if more than 1 round)
                if ($e2Rounds > 1) {
                    $exploreStartTime->subMinutes(($e2Rounds - 1) * $this->pp('e_duration_break'));
                }
                
                $exploreStartTime->subMinutes($this->pp('e_ready_action'));
                $exploreStartTime->subMinutes($this->pp('e2_duration_opening'));
                
                // Write start time for ExploreGenerator to pick up
                $this->integratedExplore->startTime = $exploreStartTime->current();

            } elseif ($this->exploreMode() == ExploreMode::INTEGRATED_AFTERNOON->value) {
                // For INTEGRATED_AFTERNOON: Ensure awards don't start before Explore is complete
                // Compare cTime (Challenge end) with exploreEndTime (Explore end) and use the later one
                $exploreEnd = $this->integratedExplore->exploreEndTime;
                if ($exploreEnd !== null) {
                    // Convert both to DateTime for comparison
                    $baseDate = $this->cTime->current()->format('Y-m-d');
                    $cTime = new \DateTime($baseDate . ' ' . $this->cTime->format('H:i'));
                    $exploreTime = new \DateTime($baseDate . ' ' . $exploreEnd);
                    
                    // Use the later time
                    if ($exploreTime > $cTime) {
                        $this->cTime->setTime($exploreEnd);
                    }
                }
            }

            $this->writer->withGroup('g_awards', function () {
                $this->writer->insertActivity('g_awards', $this->cTime, $this->pp('g_duration_awards'));
            });
            $this->cTime->addMinutes($this->pp('g_duration_awards'));

        } else {

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
            throw new \RuntimeException("Fehler beim Generieren der Challenge-Preisverleihung (Explore: " . ($explore ? 'aktiv' : 'inaktiv') . "): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get the robot game time cursor
     * Used for coordinating with Explore awards timing in integrated mode
     */
    public function getRTime(): TimeCursor
    {
        return $this->rTime;
    }

}