<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class  SeasonService
{
    public static function currentSeasonId(): int
    {
        $month = (int)date('n');
        $year = (int)date('Y');
        $effectiveYear = ($month <= 7) ? $year - 1 : $year;
        $current_season_id = DB::table('m_season')->where('year', $effectiveYear)->id;
        if (!$current_season_id) {
            Log::error("Could not find current season");
        }
        return $current_season_id;
    }
}
