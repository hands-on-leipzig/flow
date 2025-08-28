<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;

class GeneratePlanService
{
    public function run(int $planId): void
    {
        Log::info("GeneratePlanService started", ['planId' => $planId]);

        $startLevel = ob_get_level();
        ob_start();

        try {
            require_once base_path("legacy/generator/generator_main.php");

            $GLOBALS['DEBUG'] = 0;

            if (function_exists('g_generator')) {
                g_generator($planId);
            } else {
                Log::warning('g_generator() not found', ['planId' => $planId]);
            }
        } catch (\Throwable $e) {
            Log::error('GeneratePlanService failed', [
                'planId' => $planId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            while (ob_get_level() > $startLevel) {
                ob_end_clean();
            }
        }

        Log::info("GeneratePlanService completed", ['planId' => $planId]);
    }
}