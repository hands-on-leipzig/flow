<?php

namespace App\Support;

class IntegratedExploreState
{
    public int $duration = 0;
    public ?string $startTime = null;
    public ?string $deliberationEndTime = null;  // When Explore deliberations end (after e_ready_awards buffer)
    public ?string $rg1EndTime = null;            // When Robot Game Round 1 ends
}

