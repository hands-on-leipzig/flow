<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;


class ChallengeGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $cTime;

    public function __construct(ActivityWriter $writer, TimeCursor $cTime, TimeCursor $jTime, TimeCursor $rTime, int $planId)
    {
        $this->writer = $writer;
        $this->rTime  = $rTime;
        $this->jTime  = $jTime;
        $this->cTime  = $cTime;

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

    public function briefings(\DateTime $t, int $cDay): void
    {
        // === COACH BRIEFING ===
        if ($cDay === 1) {
            $this->writer->withGroup('c_coach_briefing', function () use ($t) {
                $start = (clone $t)->modify('-' . (pp('c_duration_briefing') + pp('c_ready_opening')) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('c_coach_briefing', $cursor, pp('c_duration_briefing'));
            });
        }

        // === JUDGE BRIEFING ===
        $this->writer->withGroup('c_judge_briefing', function () use ($t) {
            if (!pp('j_briefing_after_opening')) {
                $start = (clone $t)->modify('-' . (pp('j_duration_briefing') + pp('c_ready_opening')) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('c_judge_briefing', $cursor, pp('j_duration_briefing'));
            } else {
                $this->jTime->addMinutes(pp('j_ready_briefing'));
                $this->writer->insertActivity('c_judge_briefing', $this->jTime, pp('j_duration_briefing'));
                $this->jTime->addMinutes(pp('j_duration_briefing'));
            }
        });

        // === REFEREE BRIEFING ===
        $this->writer->withGroup('r_referee_briefing', function () use ($t, $cDay) {
            if (!pp('r_briefing_after_opening')) {
                $durationKey = $cDay === 1 ? 'r_duration_briefing' : 'r_duration_briefing_2';
                $start = (clone $t)->modify('-' . (pp($durationKey) + pp('c_ready_opening')) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('r_referee_briefing', $cursor, pp($durationKey));
            } else {
                $this->rTime->addMinutes(pp('r_ready_briefing'));
                $durationKey = $cDay === 1 ? 'r_duration_briefing' : 'r_duration_briefing_2';
                $this->writer->insertActivity('r_referee_briefing', $this->rTime, pp($durationKey));
                $this->rTime->addMinutes(pp($durationKey));
            }
        });

        // Buffer nach Briefings
        $this->jTime->addMinutes(pp('j_ready_action'));
        $this->rTime->addMinutes(pp('r_ready_action'));
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

    
}