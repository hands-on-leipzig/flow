<?php

namespace App\Core;

use App\Support\PlanParameter;
use Illuminate\Support\Facades\Log;

class ChallengeGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $cTime;

    public function __construct(ActivityWriter $writer, TimeCursor $cTime, TimeCursor $jTime, TimeCursor $rTime)
    {
        $this->writer = $writer;
        $this->rTime  = $rTime;
        $this->jTime  = $jTime;
        $this->cTime  = $cTime;
    }

    public function presentations(): void
    {
        $this->writer->insertActivityGroup('c_presentations');

        $duration = pp("c_presentations") * pp("c_duration_presentation") + 5;
        $this->writer->insertActivity('c_presentations', $this->rTime, $duration);

        $this->rTime->addMinutes($duration);

        if (!pp("c_presentations_last")) {
            $this->writer->insertPoint('presentations', pp("c_ready_presentations"), $this->rTime);
        } else {
            $this->writer->insertPoint('presentations', pp("c_ready_awards"), $this->rTime);
        }
    }

    public function briefings(\DateTime $t, int $cDay): void
    {
        // Coaches: immer vor Opening am 1. Tag
        if ($cDay === 1) {
            $this->writer->insertActivityGroup('c_coach_briefing');

            $start = (clone $t)->modify('-' . (pp("c_duration_briefing") + pp("c_ready_opening")) . ' minutes');
            $cursor = new TimeCursor($start);
            $this->writer->insertActivity('c_coach_briefing', $cursor, pp("c_duration_briefing"));
        }

        // Judges: Organizer entscheidet ob vor/nach Opening
        $this->writer->insertActivityGroup('c_judge_briefing');

        if (!pp("j_briefing_after_opening")) {
            $start = (clone $t)->modify('-' . (pp("j_duration_briefing") + pp("c_ready_opening")) . ' minutes');
            $cursor = new TimeCursor($start);
            $this->writer->insertActivity('c_judge_briefing', $cursor, pp("j_duration_briefing"));
        } else {
            $this->jTime->addMinutes(pp("j_ready_briefing"));
            $this->writer->insertActivity('c_judge_briefing', $this->jTime, pp("j_duration_briefing"));
            $this->jTime->addMinutes(pp("j_duration_briefing"));
        }

        // Referees: beide Tage, Umfang abhängig von Tag
        $this->writer->insertActivityGroup('r_referee_briefing');

        if (!pp("r_briefing_after_opening")) {
            if ($cDay === 1) {
                $start = (clone $t)->modify('-' . (pp("r_duration_briefing") + pp("c_ready_opening")) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('r_referee_briefing', $cursor, pp("r_duration_briefing"));
            } else {
                $start = (clone $t)->modify('-' . (pp("r_duration_briefing_2") + pp("c_ready_opening")) . ' minutes');
                $cursor = new TimeCursor($start);
                $this->writer->insertActivity('r_referee_briefing', $cursor, pp("r_duration_briefing_2"));
            }
        } else {
            $this->rTime->addMinutes(pp("r_ready_briefing"));

            if ($cDay === 1) {
                $this->writer->insertActivity('r_referee_briefing', $this->rTime, pp("r_duration_briefing"));
                $this->rTime->addMinutes(pp("r_duration_briefing"));
            } else {
                $this->writer->insertActivity('r_referee_briefing', $this->rTime, pp("r_duration_briefing_2"));
                $this->rTime->addMinutes(pp("r_duration_briefing_2"));
            }
        }

        // Nachbereitung: Buffer für Judges & Referees vor erster Action
        $this->jTime->addMinutes(pp("j_ready_action"));
        $this->rTime->addMinutes(pp("r_ready_action"));
    }

    public function judgingOneRound(int $cBlock, int $jT): void
    {
        // Neue ActivityGroup für ein Judging-Paket
        $this->writer->insertActivityGroup('c_judging_package');

        // 1) Judging WITH team
        for ($jLane = 1; $jLane <= pp("j_lanes"); $jLane++) {
            // Nicht alle Lanes sind zwingend belegt
            if ($jT + $jLane <= pp("c_teams")) {
                $this->writer->insertActivity(
                    'c_with_team',
                    $this->jTime,
                    pp("j_duration_with_team"),
                    $jLane,               // jury_lane
                    $jT + $jLane          // jury_team
                );
            }
        }
        $this->jTime->addMinutes(pp("j_duration_with_team"));

        // 2) Scoring WITHOUT team
        for ($jLane = 1; $jLane <= pp("j_lanes"); $jLane++) {
            if ($jT + $jLane <= pp("c_teams")) {
                $this->writer->insertActivity(
                    'c_scoring',
                    $this->jTime,
                    pp("j_duration_scoring"),
                    $jLane,               // jury_lane
                    $jT + $jLane          // jury_team
                );
            }
        }
        $this->jTime->addMinutes(pp("j_duration_scoring"));

        // 3) Pausenlogik vor nächster Runde
        if ((pp("j_rounds") == 4 && $cBlock == 2) ||
            (pp("j_rounds") > 4 && $cBlock == 3)) {
            // Lunch break (nur wenn keine harte Pause gesetzt ist)
            if (pp('c_duration_lunch_break') == 0) {
                $this->jTime->addMinutes(pp("j_duration_lunch"));
            }
        } else {
            // normale Pause, aber nicht nach letztem Block
            if ($cBlock < pp("j_rounds")) {
                $this->jTime->addMinutes(pp("j_duration_break"));
            }
        }
    }
}