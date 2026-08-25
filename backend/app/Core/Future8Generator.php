<?php

namespace App\Core;

use App\Enums\ExploreMode;
use App\Support\IntegratedExploreState;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use Illuminate\Support\Facades\Log;

class Future8Generator implements ChallengeShapedLead
{
    use UsesPlanParameter;

    private ActivityWriter $writer;

    private TimeCursor $rTime;

    private TimeCursor $jTime;

    private TimeCursor $cTime;

    private RobotGameGenerator $robotGame;

    private IntegratedExploreState $integratedExplore;

    public function __construct(
        ActivityWriter $writer,
        PlanParameter $params,
        IntegratedExploreState $integratedExplore
    ) {
        $this->writer = $writer;
        $this->params = $params;
        $this->integratedExplore = $integratedExplore;

        $baseDate = $params->get('g_date');
        $this->cTime = new TimeCursor(clone $baseDate);
        $this->jTime = new TimeCursor(clone $baseDate);
        $this->rTime = new TimeCursor(clone $baseDate);

        $teams = $params->get('f8_teams');
        $lanes = $params->get('f8_lanes');
        $fields = $params->get('f8_fields');

        $jRounds = max(4, (int) ceil($teams / max(1, $lanes)));
        $params->add('f8_j_rounds', $jRounds, 'integer');

        $matchesPerRound = (int) ceil($teams / 2);
        $params->add('f8_r_matches_per_round', $matchesPerRound, 'integer');

        $needVolunteer = $matchesPerRound != ($teams / 2);
        $params->add('f8_r_need_volunteer', $needVolunteer, 'boolean');

        $asym = $fields == 4 && (($teams % 4 == 1) || ($teams % 4 == 2));
        $params->add('f8_r_asym', $asym, 'boolean');
    }

    public function judgingOneRound(int $cBlock, int $jT): void
    {
        $jTime = $this->jTime;

        $this->writer->withGroup('f8_j_package', function () use ($cBlock, $jT, $jTime) {
            $activities = [];

            $withTeamStart = $jTime->current()->format('Y-m-d H:i:s');
            $withTeamEndCursor = $jTime->copy();
            $withTeamEndCursor->addMinutes($this->pp('f8_j_duration_with_team'));
            $withTeamEnd = $withTeamEndCursor->current()->format('Y-m-d H:i:s');

            for ($jL = 1; $jL <= $this->pp('f8_lanes'); $jL++) {
                if ($jT + $jL <= $this->pp('f8_teams')) {
                    $activities[] = [
                        'activityTypeCode' => 'f8_j_with_team',
                        'start' => $withTeamStart,
                        'end' => $withTeamEnd,
                        'juryLane' => $jL,
                        'juryTeam' => $jT + $jL,
                    ];
                }
            }
            $jTime->addMinutes($this->pp('f8_j_duration_with_team'));

            $scoringStart = $jTime->current()->format('Y-m-d H:i:s');
            $scoringEndCursor = $jTime->copy();
            $scoringEndCursor->addMinutes($this->pp('f8_j_duration_scoring'));
            $scoringEnd = $scoringEndCursor->current()->format('Y-m-d H:i:s');

            for ($jL = 1; $jL <= $this->pp('f8_lanes'); $jL++) {
                if ($jT + $jL <= $this->pp('f8_teams')) {
                    $activities[] = [
                        'activityTypeCode' => 'f8_j_scoring',
                        'start' => $scoringStart,
                        'end' => $scoringEnd,
                        'juryLane' => $jL,
                        'juryTeam' => $jT + $jL,
                    ];
                }
            }
            $jTime->addMinutes($this->pp('f8_j_duration_scoring'));

            if (! empty($activities)) {
                $this->writer->insertActivitiesBulk($activities);
            }

            $isLunchRound = (($this->pp('f8_j_rounds') == 4 && $cBlock == 2)
                || ($this->pp('f8_j_rounds') > 4 && $cBlock == 3));

            if ($isLunchRound) {
                if ($this->pp('f8_duration_lunch_break') == 0) {
                    $jTime->addMinutes($this->pp('f8_j_duration_lunch'));
                }
            } elseif ($cBlock < $this->pp('f8_j_rounds')) {
                $jTime->addMinutes($this->pp('f8_j_duration_break'));
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
                $this->cTime->setTime($this->pp('f8_start_opening'));
                $this->jTime->set($this->cTime->current());
                $this->rTime->set($this->cTime->current());

                $this->writer->withGroup('f8_opening', function () {
                    $this->writer->insertActivity('f8_opening', $this->cTime, $this->pp('f8_duration_opening'));
                });

                $this->jTime->addMinutes($this->pp('f8_duration_opening'));
                $this->rTime->addMinutes($this->pp('f8_duration_opening'));
            }

            $this->briefings($this->cTime->current());
        } catch (\Throwable $e) {
            Log::error('Future8Generator: Error in openings and briefings', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Fehler beim Generieren der Future 8+ Eröffnung und Briefings: '.$e->getMessage(), 0, $e);
        }
    }

    public function briefings(\DateTime $t): void
    {
        $this->writer->withGroup('f8_briefing', function () use ($t) {
            $cursor = new TimeCursor($t);
            $cursor->subMinutes($this->pp('f8_duration_briefing') + $this->pp('f8_ready_opening'));
            $this->writer->insertActivity('f8_briefing', $cursor, $this->pp('f8_duration_briefing'));
        });

        $this->writer->withGroup('f8_j_briefing', function () use ($t) {
            if (! $this->pp('f8_j_briefing_after_opening')) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($this->pp('f8_j_duration_briefing') + $this->pp('f8_ready_opening'));
                $this->writer->insertActivity('f8_j_briefing', $cursor, $this->pp('f8_j_duration_briefing'));
            } else {
                $cursor = $this->jTime->copy();
                $cursor->addMinutes($this->pp('f8_j_ready_briefing'));
                $this->writer->insertActivity('f8_j_briefing', $cursor, $this->pp('f8_j_duration_briefing'));
                $this->jTime->addMinutes($this->pp('f8_j_ready_briefing') + $this->pp('f8_j_duration_briefing'));
            }
            $this->jTime->addMinutes($this->pp('f8_j_ready_action'));
        });

        $this->writer->withGroup('f8_r_briefing', function () use ($t) {
            $duration = $this->pp('f8_r_duration_briefing');
            if (! $this->pp('f8_r_briefing_after_opening')) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($duration + $this->pp('f8_ready_opening'));
                $this->writer->insertActivity('f8_r_briefing', $cursor, $duration);
            } else {
                $cursor = $this->rTime->copy();
                $cursor->addMinutes($this->pp('f8_r_ready_briefing'));
                $this->writer->insertActivity('f8_r_briefing', $cursor, $duration);
                $this->rTime->addMinutes($this->pp('f8_r_ready_briefing') + $duration);
            }

            $this->rTime->addMinutes($this->pp('f8_r_ready_action'));
        });
    }

    public function main(bool $explore = false, ?callable $afterRG1Callback = null): void
    {
        Log::info('Future8Generator::main', [
            'plan_id' => $this->pp('g_plan'),
            'f8_teams' => $this->pp('f8_teams'),
            'f8_lanes' => $this->pp('f8_lanes'),
            'f8_j_rounds' => $this->pp('f8_j_rounds'),
            'f8_fields' => $this->pp('f8_fields'),
            'explore' => $explore,
        ]);

        try {
            $matchPlan = (new Future8MatchPlanBuilder($this->params))->build();
            $this->robotGame = new RobotGameGenerator(
                $this->writer,
                $this->params,
                $this->rTime,
                $this->integratedExplore,
                $matchPlan,
                RobotGameWriteConfig::future8()
            );

            $jTimeEarliest = clone $this->jTime;
            $jT = 0;
            $jT4J = $this->pp('f8_j_duration_with_team') + $this->pp('f8_duration_transfer');

            for ($cBlock = 1; $cBlock <= $this->pp('f8_j_rounds'); $cBlock++) {
                $this->alignJudgingWithRobotGame($cBlock, $jTimeEarliest, $jT4J);
                $this->judgingOneRound($cBlock, $jT);
                $jT += $this->pp('f8_lanes');
                $this->insertRobotGameRoundForBlock($cBlock, $afterRG1Callback);
                $this->maybeInsertHardLunch($cBlock);
            }

            $this->syncCeremonyTimeAfterMain();
            $this->insertDeliberations();
        } catch (\Throwable $e) {
            Log::error('Future8Generator: Error in main', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Fehler beim Generieren der Future 8+ Hauptaktivitäten: '.$e->getMessage(), 0, $e);
        }
    }

    private function alignJudgingWithRobotGame(int $cBlock, TimeCursor &$jTimeEarliest, mixed $jT4J): void
    {
        $rDuration = ($cBlock == 1)
            ? $this->pp('f8_r_duration_test_match')
            : $this->pp('f8_r_duration_match');

        if ($this->jTime->current() < $jTimeEarliest->current()) {
            $this->jTime->set($jTimeEarliest->current());
        }

        if ($cBlock == $this->pp('f8_j_rounds') && ($this->pp('f8_teams') % $this->pp('f8_lanes')) !== 0) {
            $teamsInLastRound = $this->pp('f8_teams') % $this->pp('f8_lanes');
            $rMB = max(0, $this->pp('f8_r_matches_per_round') - $teamsInLastRound);
        } else {
            $rMB = $this->pp('f8_r_matches_per_round') - ceil($this->pp('f8_lanes') / 2);
        }

        if ($cBlock == 1 && $this->pp('f8_r_asym') && $this->pp('f8_j_rounds') != 4) {
            $rMB++;
        }

        if ($cBlock < $this->pp('f8_j_rounds') && $this->pp('f8_teams') <= $cBlock * $this->pp('f8_lanes')) {
            $rMB = 0;
        }

        if ($this->pp('f8_fields') == 2) {
            $rT2M = $rMB * $rDuration;
        } else {
            if ($rMB % 2 === 0) {
                $rT2M = $rMB / 2 * $rDuration;
            } else {
                $rT2M = ($rMB - 1) / 2 * $rDuration + $this->pp('f8_r_duration_next_start');
            }
        }

        $rStartTarget = clone $this->jTime;
        $rStartTarget->addMinutes($jT4J - $rT2M);

        if ($this->rTime->current() <= $rStartTarget->current()) {
            $this->rTime->set($rStartTarget->current());
        }

        if ($this->pp('f8_j_rounds') > 4 && $cBlock == 2) {
            $rA4J = 0;
        } else {
            $rMB = ceil($this->pp('f8_lanes') / 2);

            if ($this->pp('f8_fields') == 2) {
                $rA4J = $rMB * $rDuration;
            } else {
                if ($rMB % 2 === 0) {
                    $rA4J = $rMB / 2 * $rDuration + $this->pp('f8_r_duration_next_start');
                } else {
                    $rA4J = ($rMB + 1) / 2 * $rDuration;
                }
            }

            $rA4J += $this->pp('f8_duration_transfer');
        }

        $jTimeEarliest = clone $this->rTime;
        $jTimeEarliest->addMinutes($rA4J);
    }

    private function insertRobotGameRoundForBlock(int $cBlock, ?callable $afterRG1Callback): void
    {
        $insertedRg1 = false;

        switch ($cBlock) {
            case 1:
                $this->robotGame->insertOneRound(0);
                break;
            case 2:
                if ($this->pp('f8_j_rounds') == 4) {
                    $this->robotGame->insertOneRound(1);
                    $insertedRg1 = true;
                }
                break;
            case 3:
                if ($this->pp('f8_j_rounds') == 4) {
                    $this->robotGame->insertOneRound(2);
                } else {
                    $this->robotGame->insertOneRound(1);
                    $insertedRg1 = true;
                }
                break;
            case 4:
                if ($this->pp('f8_j_rounds') == 4) {
                    $this->robotGame->insertOneRound(3);
                } else {
                    $this->robotGame->insertOneRound(2);
                }
                break;
            case 5:
                $this->robotGame->insertOneRound(3);
                break;
        }

        if ($insertedRg1) {
            $this->maybeRunAfterRG1Handoff($afterRG1Callback);
        }
    }

    private function maybeRunAfterRG1Handoff(?callable $afterRG1Callback): void
    {
        if ($afterRG1Callback !== null && $this->exploreMode() == ExploreMode::INTEGRATED_MORNING->value) {
            $afterRG1Callback($this->rTime);
        }
    }

    private function maybeInsertHardLunch(int $cBlock): void
    {
        $isLunchRound = (($this->pp('f8_j_rounds') == 4 && $cBlock == 2)
            || ($this->pp('f8_j_rounds') > 4 && $cBlock == 3));

        if ($isLunchRound && $this->pp('f8_duration_lunch_break') > 0) {
            if ($this->rTime->current() < $this->jTime->current()) {
                $this->rTime->set($this->jTime->current());
            } else {
                $this->jTime->set($this->rTime->current());
            }

            $this->jTime->addMinutes($this->pp('f8_duration_lunch_break'));
            $this->rTime->addMinutes($this->pp('f8_duration_lunch_break'));
        }
    }

    private function syncCeremonyTimeAfterMain(): void
    {
        $this->cTime->set($this->jTime->current());
        $this->cTime->addMinutes(-$this->pp('f8_j_duration_scoring'));

        if ($this->rTime->current() > $this->cTime->current()) {
            $this->cTime->set($this->rTime->current());
        }
    }

    private function insertDeliberations(): void
    {
        $this->jTime->addMinutes($this->pp('f8_j_ready_deliberations'));

        if (! $this->pp('f8_j_deliberations_flex') && $this->jTime->current() < $this->rTime->current()) {
            $this->jTime->set($this->rTime->current());
        }

        $this->writer->withGroup('f8_j_deliberations', function () {
            $this->writer->insertActivity('f8_j_deliberations', $this->jTime, $this->pp('f8_j_duration_deliberations'));
        });
        $this->jTime->addMinutes($this->pp('f8_j_duration_deliberations'));
    }

    public function beginAfternoon(): void
    {
        $this->rTime->set($this->cTime->current());
        $this->rTime->addMinutes($this->pp('f8_r_duration_results'));
    }

    public function presentations(): void
    {
        $this->rTime->addMinutes($this->pp('f8_ready_presentations'));

        $duration = $this->pp('f8_presentations') * $this->pp('f8_duration_presentation') + 5;

        $this->writer->withGroup('f8_presentations', function () use ($duration) {
            $this->writer->insertActivity('f8_presentations', $this->rTime, $duration);
        });

        $this->rTime->addMinutes($duration);
        $this->rTime->addMinutes($this->pp('f8_ready_presentations'));
    }

    /**
     * Empty field slots for afternoon rounds 4 and 5. Teams are assigned onsite.
     */
    public function insertEmptyGameRound(int $round): void
    {
        $groupCode = match ($round) {
            4 => 'f8_round_4',
            5 => 'f8_round_5',
            default => throw new \InvalidArgumentException("Future 8+ empty game round must be 4 or 5, got {$round}"),
        };

        $fields = (int) $this->pp('f8_fields');
        $matches = (int) $this->pp('f8_r_matches_per_round');
        $duration = $this->pp('f8_r_duration_match');
        $nextStart = $this->pp('f8_r_duration_next_start');

        $this->writer->withGroup($groupCode, function () use ($fields, $matches, $duration, $nextStart) {
            for ($match = 1; $match <= $matches; $match++) {
                if ($fields == 2) {
                    $this->writer->insertActivity(
                        'f8_r_match',
                        $this->rTime,
                        $duration,
                        null,
                        null,
                        1,
                        null,
                        2,
                        null
                    );
                    $this->rTime->addMinutes($duration);
                } else {
                    $table1 = ($match % 2 === 1) ? 1 : 3;
                    $table2 = ($match % 2 === 1) ? 2 : 4;
                    $this->writer->insertActivity(
                        'f8_r_match',
                        $this->rTime,
                        $duration,
                        null,
                        null,
                        $table1,
                        null,
                        $table2,
                        null
                    );
                    $this->rTime->addMinutes($nextStart);
                }
            }

            if ($fields === 4) {
                $this->rTime->addMinutes($duration - $nextStart);
            }
        });

        $this->rTime->addMinutes($this->pp('f8_r_duration_break'));
    }

    public function endAfternoon(): void
    {
        $this->rTime->addMinutes($this->pp('f8_ready_awards'));
        $this->cTime->set($this->rTime->current());

        if ($this->jTime->current()->getTimestamp() > $this->cTime->current()->getTimestamp()) {
            $this->cTime->set($this->jTime->current());
        }
    }

    public function awards(bool $explore = false): void
    {
        try {
            if ($explore) {
                if ($this->exploreMode() == ExploreMode::HYBRID_BOTH->value) {
                    $exploreStartTime = clone $this->cTime;

                    $exploreStartTime->subMinutes($this->pp('e_ready_awards'));
                    $exploreStartTime->subMinutes($this->pp('e2_duration_deliberations'));
                    $exploreStartTime->subMinutes($this->pp('e_ready_deliberations'));

                    $e2Rounds = $this->pp('e2_rounds');
                    $durationPerRound = $this->pp('e_duration_with_team') + $this->pp('e_duration_scoring');
                    $exploreStartTime->subMinutes($e2Rounds * $durationPerRound);
                    if ($e2Rounds > 1) {
                        $exploreStartTime->subMinutes(($e2Rounds - 1) * $this->pp('e_duration_break'));
                    }

                    $exploreStartTime->subMinutes($this->pp('e_ready_action'));
                    $exploreStartTime->subMinutes($this->pp('e2_duration_opening'));

                    $this->integratedExplore->startTime = $exploreStartTime->current();
                } elseif ($this->exploreMode() == ExploreMode::INTEGRATED_AFTERNOON->value) {
                    $exploreEnd = $this->integratedExplore->exploreEndTime;
                    if ($exploreEnd !== null) {
                        $baseDate = $this->cTime->current()->format('Y-m-d');
                        $cTime = new \DateTime($baseDate.' '.$this->cTime->format('H:i'));
                        $exploreTime = new \DateTime($baseDate.' '.$exploreEnd);

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
                $this->writer->withGroup('f8_awards', function () {
                    $this->writer->insertActivity('f8_awards', $this->cTime, $this->pp('f8_duration_awards'));
                });
                $this->cTime->addMinutes($this->pp('f8_duration_awards'));
            }
        } catch (\Throwable $e) {
            Log::error('Future8Generator: Error in awards', [
                'explore' => $explore,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Fehler beim Generieren der Future 8+ Preisverleihung: '.$e->getMessage(), 0, $e);
        }
    }
}
