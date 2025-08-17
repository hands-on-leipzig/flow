<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeneratePlan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $planId;

    // tune as you like
    public $timeout = 300;      // seconds
    public $tries = 1;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
    }

    public function handle(): void
    {
        Log::info("Generating plan: {$this->planId}");

        // Use Laravel’s DB connection, not the legacy one
        // If legacy code insists on including its own DB bootstrap,
        // we still hard-mute all output so it can’t break anything.

        $startLevel = ob_get_level();
        ob_start(); // catch all echoes/prints

        try {
            // Load legacy code
            require_once base_path('legacy/generator/generator_functions.php');
            require_once base_path('legacy/generator/generator_db.php'); // only if absolutely needed

            // Silence legacy debug
            if (!isset($GLOBALS['DEBUG'])) {
                $GLOBALS['DEBUG'] = 0;
            } else {
                $GLOBALS['DEBUG'] = 0;
            }

            // If possible, remove this and let the legacy use Laravel’s connection instead.
            // If you MUST call it, keep it inside the buffer:
            if (function_exists('db_connect_persistent')) {
                @db_connect_persistent();
            }

            // Run generator
            if (function_exists('g_generator')) {
                g_generator($this->planId);
            } else {
                Log::warning('g_generator() not found for plan ' . $this->planId);
            }
        } catch (\Throwable $e) {
            Log::error('GeneratePlan failed: ' . $e->getMessage(), ['planId' => $this->planId]);
            throw $e; // let Laravel retry/fail the job
        } finally {
            // Drop anything the legacy code might have printed
            while (ob_get_level() > $startLevel) {
                ob_end_clean();
            }
        }

        Log::info('GeneratePlan completed', ['planId' => $this->planId]);
    }
}
