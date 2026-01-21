<?php

namespace App\Support;

class IntegratedExploreState
{
    public int $duration = 0;
    public ?string $startTime = null;
    public ?string $deliberationEndTime = null;  // When Explore deliberations end (after e_ready_awards buffer) - for INTEGRATED_MORNING
    public ?string $rg1EndTime = null;            // When Robot Game Round 1 ends
    public ?string $exploreEndTime = null;        // When Explore activities end (for INTEGRATED_AFTERNOON joint awards)
}

