<?php

namespace App\Support;

use DateTime;

class IntegratedExploreState
{
    /** Precomputed Explore hole (finale RG2 path still uses this). */
    public int $duration = 0;

    /** Challenge-led start for an Explore ceremony (datetime, not clock-only). */
    public ?DateTime $startTime = null;

    /** End of Explore morning deliberations (plus ready-for-awards peek). */
    public ?DateTime $deliberationsEnd = null;

    /** Instant RG1 ended, before the post-RG1 break. */
    public ?DateTime $rg1End = null;

    /** Explore afternoon judging end (H:i still; end-of-day joint awards). */
    public ?string $exploreEndTime = null;
}
