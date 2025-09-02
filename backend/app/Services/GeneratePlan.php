<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Glob;  

class GeneratePlan
{
    public static function run(int $planId): void
    {
        // Lösche zugehörige Gruppen und Aktivitäten
        $groupIds = DB::table('activity_group')
            ->where('plan', $planId)
            ->pluck('id');

        DB::table('activity')
            ->whereIn('activity_group', $groupIds)
            ->delete();

        DB::table('activity_group')
            ->where('plan', $planId)
            ->delete();

        // Generator starten
        require_once base_path("legacy/generator/generator_main.php");
        g_generator($planId);
    }
}