<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;

class FinaleGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $cTime;   // Challenge/main timeline used for awards etc.
    private TimeCursor $rTime;   // Robot game timeline (final rounds)
    private TimeCursor $lcTime;  // Live Challenge timeline (day before)
    private int $cDay = 1;       // Event day for main action: 1 or 2

    public function __construct(ActivityWriter $writer, TimeCursor $cTime, TimeCursor $rTime, TimeCursor $lcTime, int $planId)
    {
        $this->writer = $writer;
        $this->cTime  = $cTime;
        $this->rTime  = $rTime;
        $this->lcTime = $lcTime;

        // Keep consistent with other generators: read parameters here.
        $params = PlanParameter::load($planId);

        // Determine Live Challenge day and main event day based on parameters
        $gDate = clone $params->get('g_date'); // DateTime
        if ((bool) $params->get('g_finale')) {
            $lcDate = clone $gDate;
            [$hours, $minutes] = explode(':', (string) $params->get('f_start_day_1'));
            $lcDate->setTime((int)$hours, (int)$minutes);
            $this->lcTime = new TimeCursor($lcDate);

            // Main action on next day
            $gDate->modify('+1 day');
            $this->cDay = 2;
        } else {
            // Neutral placeholder when no finale
            $this->lcTime = new TimeCursor(new \DateTime());
            $this->cDay = 1;
        }

        // Log::debug('FinaleGenerator constructed', [
        //     'plan_id' => $planId,
        // ]);
    }

    public function lcTime(): TimeCursor
    {
        return $this->lcTime;
    }

    public function cDay(): int
    {
        return $this->cDay;
    }
}


