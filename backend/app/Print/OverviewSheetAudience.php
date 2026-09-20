<?php

namespace App\Print;

final class OverviewSheetAudience
{
    /** Must match PRINT_FIT_AUDIENCE_ROLE_IDS in PublicSchedule.vue */
    public const ROLE_IDS = [6, 10, 14, 24];

    public const DEFAULT_ROLE_ID = 14;

    public static function allows(int $roleId): bool
    {
        return in_array($roleId, self::ROLE_IDS, true);
    }
}
