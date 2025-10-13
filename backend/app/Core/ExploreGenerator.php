<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;


class ExploreGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $eTime;
    private TimeCursor $rTime;

    use UsesPlanParameter;

    public function __construct(ActivityWriter $writer, PlanParameter $params)
    {
        $this->writer = $writer;
        
        // Create time cursors from base date
        $baseDate = $params->get('g_date');
        $this->eTime = new TimeCursor(clone $baseDate);
        $this->rTime = new TimeCursor(clone $baseDate);

        // Derived parameters formerly computed in Core::initialize for Explore
        $e1Teams = (int) ($params->get('e1_teams') ?? 0);
        if ($e1Teams > 0) {
            $e1Lanes = (int) ($params->get('e1_lanes') ?? 1);
            $e1Rounds = (int) ceil($e1Teams / max(1, $e1Lanes));
            $params->add('e1_rounds', $e1Rounds, 'integer');
        }

        $e2Teams = (int) ($params->get('e2_teams') ?? 0);
        if ($e2Teams > 0) {
            $e2Lanes = (int) ($params->get('e2_lanes') ?? 1);
            $e2Rounds = (int) ceil($e2Teams / max(1, $e2Lanes));
            $params->add('e2_rounds', $e2Rounds, 'integer');
        }
    }

    public function openingsAndBriefings(int $group, bool $challenge = false): void
    {
        $startOpening = clone $this->eTime; 

        if ($challenge) {

            $this->eTime->addMinutes($this->pp('g_duration_opening'));

        } else {

            $this->eTime->setTime($this->pp("e{$group}_start_opening"));

            $this->writer->withGroup('e_opening', function () use ($group) {
                $this->writer->insertActivity('e_opening', $this->eTime, $this->pp("e{$group}_duration_opening"));
            });

            $this->eTime->addMinutes($this->pp("e{$group}_duration_opening"));

            if($group == 1) {
                Log::info('Explore stand-alone morning: teams=' . $this->pp('e1_teams') . ', lanes=' . $this->pp('e1_lanes') . ', rounds=' . $this->pp('e1_rounds'));
            } else {
                Log::info('Explore stand-alone afternoon: teams=' . $this->pp('e2_teams') . ', lanes=' . $this->pp('e2_lanes') . ', rounds=' . $this->pp('e2_rounds'));
            }

        }

        $this->briefings($startOpening->current(), $group);

    }

    public function briefings(\DateTime $t, int $group): void
    {

        $this->writer->withGroup('e_coach_briefing', function () use ($t, $group) {
            $start = (clone $t)->modify('-' . ($this->pp("e{$group}_duration_briefing_t") + $this->pp("e_ready_opening")) . ' minutes');
            $cursor = new TimeCursor($start);
            $this->writer->insertActivity('e_coach_briefing', $cursor, $this->pp("e{$group}_duration_briefing_t"));
        });

        $this->writer->withGroup('e_judge_briefing', function () use ($t, $group) {
            if (!$this->pp("e_briefing_after_opening_j")) {
                $start = (clone $t)->modify('-' . ($this->pp("e{$group}_duration_briefing_j") + $this->pp("e_ready_opening")) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('e_judge_briefing', $cursor, $this->pp("e{$group}_duration_briefing_j"));
            } else {
                $this->eTime->addMinutes($this->pp("e_ready_briefing"));
                $this->writer->insertActivity('e_judge_briefing', $this->eTime, $this->pp("e{$group}_duration_briefing_j"));
                $this->eTime->addMinutes($this->pp("e{$group}_duration_briefing_j"));
            }
        });

        $this->eTime->addMinutes($this->pp("e_ready_action"));
    }


    public function judgingAndDeliberations(int $group): void
    {
        Log::debug("Explore judging for group {$group}");

        $lanes = $this->pp("e{$group}_lanes");
        $rounds = $this->pp("e{$group}_rounds");
        $teams = $this->pp("e{$group}_teams");

        $teamOffset = ($group === 1) ? 0 : $this->pp("e1_teams");
        $laneOffset = ($group === 1) ? 0 : $this->pp("e1_lanes");

        $this->writer->withGroup('e_judging_package', function () use ($rounds, $lanes, $teams, $teamOffset, $laneOffset) {
            for ($round = 1; $round <= $rounds; $round++) {
                // WITH team
                for ($lane = 1; $lane <= $lanes; $lane++) {
                    $team = ceil($teams / $lanes) * ($lane - 1) + $round;
                    if ($team <= $teams) {
                        $this->writer->insertActivity('e_with_team', $this->eTime, $this->pp("e_duration_with_team"), $lane + $laneOffset, $team + $teamOffset);
                    }
                }
                $this->eTime->addMinutes($this->pp("e_duration_with_team"));

                // Scoring
                for ($lane = 1; $lane <= $lanes; $lane++) {
                    $team = ($lane - 1) * $rounds + $round;
                    if ($team <= $teams) {
                        $this->writer->insertActivity('e_scoring', $this->eTime, $this->pp("e_duration_scoring"), $lane + $laneOffset, $team + $teamOffset);
                    }
                }
                $this->eTime->addMinutes($this->pp("e_duration_scoring"));

                if ($round < $rounds) {
                    $this->eTime->addMinutes($this->pp("e_duration_break"));
                }
            }
        });

        // Buffer before all judges meet for deliberations
        $this->eTime->addMinutes($this->pp('e_ready_deliberations'));

        // Deliberations
        $this->writer->withGroup('e_deliberations', function () use ($group) {
            $this->writer->insertActivity('e_deliberations', $this->eTime, $this->pp("e{$group}_duration_deliberations"));
        });

        $this->eTime->addMinutes($this->pp("e{$group}_duration_deliberations"));
    }

    public function awards(int $group, bool $challenge = false): void   
    {
        if (!$challenge) {

            $this->eTime->addMinutes($this->pp("e_ready_awards"));
            $this->writer->withGroup('e_awards', function () use ($group) {
                $this->writer->insertActivity('e_awards', $this->eTime, $this->pp("e{$group}_duration_awards"));
            });
            $this->eTime->addMinutes($this->pp("e{$group}_duration_awards"));
        }

    }


}
