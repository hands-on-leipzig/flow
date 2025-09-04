<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class GeneratePlan
{
    public static function run(int $planId): void
    {
        /* not needed anymore, because of cascading delete 

        // Remove existing activities
        $groupIds = DB::table('activity_group')
            ->where('plan', $planId)
            ->pluck('id');

        DB::table('activity')
            ->whereIn('activity_group', $groupIds)
            ->delete();            

        --- */    

        DB::table('activity_group')
            ->where('plan', $planId)
            ->delete();

        // Start the generator
        require_once base_path("legacy/generator/generator_main.php");

        try {
            g_generator($planId);
        } catch (RuntimeException $e) {
            Log::error("Fehler beim Generieren des Plans {$planId}: " . $e->getMessage());
            throw new \Exception("Plan konnte nicht erzeugt werden: " . $e->getMessage());
        }

        // Note the end
        DB::table('s_generator')
            ->where('plan', $planId)
            ->latest('id') // letzter Lauf für diesen Plan
            ->limit(1)
            ->update([
                'end' => \Carbon\Carbon::now(),
            ]);

        DB::table('plan')
        ->where('id', $planId)
        ->update(['last_change' => \Carbon\Carbon::now()]);


    }
}