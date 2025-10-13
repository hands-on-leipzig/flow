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

    public function __construct(ActivityWriter $writer, TimeCursor $cTime, TimeCursor $rTime, TimeCursor $lcTime, int $planId)
    {
        $this->writer = $writer;
        $this->cTime  = $cTime;
        $this->rTime  = $rTime;
        $this->lcTime = $lcTime;

        // Keep consistent with other generators: read parameters here.
        // Derived values specific to finale can be computed here in future steps.
        $params = PlanParameter::load($planId);

        Log::debug('FinaleGenerator constructed', [
            'plan_id' => $planId,
        ]);
    }
}


