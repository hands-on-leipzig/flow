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

    public function __construct(ActivityWriter $writer, TimeCursor $eTime, TimeCursor $rTime)
    {
        $this->writer = $writer;
        $this->eTime  = $eTime;
        $this->rTime  = $rTime;

    }

    public function briefings(DateTime $openingTime, int $group): void
    {
        Log::debug("Explore briefings for group {$group}");

        $dCoach = pp("e{$group}_duration_briefing_t");
        $dJudge = pp("e{$group}_duration_briefing_j");

        $this->writer->withGroup('e_coach_briefing', function () use ($openingTime, $dCoach) {
            $start = (clone $openingTime)->modify('-' . ($dCoach + pp("e_ready_opening")) . ' minutes');
            $cursor = new TimeCursor($start);
            $this->writer->insertActivity('e_coach_briefing', $cursor, $dCoach);
        });

        $this->writer->withGroup('e_judge_briefing', function () use ($openingTime, $dJudge) {
            if (!pp("e_briefing_after_opening_j")) {
                $start = (clone $openingTime)->modify('-' . ($dJudge + pp("e_ready_opening")) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('e_judge_briefing', $cursor, $dJudge);
            } else {
                $this->eTime->addMinutes(pp("e_ready_briefing"));
                $this->writer->insertActivity('e_judge_briefing', $this->eTime, $dJudge);
                $this->eTime->addMinutes($dJudge);
            }
        });

        $this->eTime->addMinutes(pp("e_ready_action"));
    }

    public function judging(int $group): void
    {
        Log::debug("Explore judging for group {$group}");

        $lanes = pp("e{$group}_lanes");
        $rounds = pp("e{$group}_rounds");
        $teams = pp("e{$group}_teams");

        $teamOffset = ($group === 1) ? 0 : pp("e1_teams");
        $laneOffset = ($group === 1) ? 0 : pp("e1_lanes");

        $this->writer->withGroup('e_judging_package', function () use ($rounds, $lanes, $teams, $teamOffset, $laneOffset) {
            for ($round = 1; $round <= $rounds; $round++) {
                // WITH team
                for ($lane = 1; $lane <= $lanes; $lane++) {
                    $team = ceil($teams / $lanes) * ($lane - 1) + $round;
                    if ($team <= $teams) {
                        $this->writer->insertActivity('e_with_team', $this->eTime, pp("e_duration_with_team"), $lane + $laneOffset, $team + $teamOffset);
                    }
                }
                $this->eTime->addMinutes(pp("e_duration_with_team"));

                // Scoring
                for ($lane = 1; $lane <= $lanes; $lane++) {
                    $team = ($lane - 1) * $rounds + $round;
                    if ($team <= $teams) {
                        $this->writer->insertActivity('e_scoring', $this->eTime, pp("e_duration_scoring"), $lane + $laneOffset, $team + $teamOffset);
                    }
                }
                $this->eTime->addMinutes(pp("e_duration_scoring"));

                if ($round < $rounds) {
                    $this->eTime->addMinutes(pp("e_duration_break"));
                }
            }
        });
    }

    public function deliberationsAndAwards(int $group): void
    {
        $this->eTime->addMinutes(pp("e_ready_deliberations"));
        $this->writer->withGroup('e_deliberations', function () use ($group) {
            $this->writer->insertActivity('e_deliberations', $this->eTime, pp("e{$group}_duration_deliberations"));
            $this->eTime->addMinutes(pp("e{$group}_duration_deliberations"));
        });

        $this->eTime->addMinutes(pp("e_ready_awards"));
        $this->writer->withGroup('e_awards', function () use ($group) {
            $this->writer->insertActivity('e_awards', $this->eTime, pp("e{$group}_duration_awards"));
            $this->eTime->addMinutes(pp("e{$group}_duration_awards"));
        });
    }

    public function opening(int $group): DateTime
    {
        $start = explode(':', pp("e{$group}_start_opening"));
        $this->eTime->current()->setTime((int) $start[0], (int) $start[1]);
        $openingStart = clone $this->eTime->current();

        $this->writer->withGroup('e_opening', function () use ($group) {
            $this->writer->insertActivity('e_opening', $this->eTime, pp("e{$group}_duration_opening"));
            $this->eTime->addMinutes(pp("e{$group}_duration_opening"));
        });

        return $openingStart;
    }

    public function isSupported(int $group): bool
    {
        return db_check_supported_plan(
            ID_FP_EXPLORE,
            pp("e{$group}_teams"),
            pp("e{$group}_lanes")
        );
    }

    public function eTime(): TimeCursor
    {
        return $this->eTime;
    }

    public function rTime(): TimeCursor
    {
        return $this->rTime;
    }
}
