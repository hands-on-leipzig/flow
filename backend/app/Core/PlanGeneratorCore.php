<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;

class PlanGeneratorCore
{
    private int $planId;
    private ActivityWriter $writer;

    private TimeCursor $cTime;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $eTime;
    private TimeCursor $lcTime;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
        $this->writer = new ActivityWriter($planId);
    }

    public function generate(): void
    {
        // Parameter laden
        PlanParameter::load($this->planId);

        Log::info("PlanGeneratorCore: Start generation for plan {$this->planId}");

        // Startzeit festlegen (jetzt hart, später gern aus Parametern wie pp('g_day_start'))
        $start = new DateTime('2025-09-30 09:00:00');

        // c_time/r_time initialisieren
        $this->cTime = new TimeCursor($start);
        $this->rTime = $this->cTime->copy();

        // Matchplan erzeugen (schreibt noch nichts in DB; Einfügen folgt in den aufrufenden Schritten)
        $matchPlan = new MatchPlan($this->writer);
        $matchPlan->create();

        // Beispiel: Eine Runde einfügen (0 = Vorrunde) mit Startzeit r_time
        $matchPlan->insertOneRound(0, $this->rTime);

        // Challenge-spezifisch: Briefings, Presentations, Judging …
        $challenge = new ChallengeGenerator($this->writer, $this->cTime, $this->jTime, $this->rTime);

        // Beispielaufrufe
        $challenge->presentations();
        $challenge->briefings(new \DateTime('2025-09-30 08:30:00'), 1);
        $challenge->judgingOneRound(1, 0);

        Log::info("PlanGeneratorCore: Finished generation for plan {$this->planId}");
    }
}