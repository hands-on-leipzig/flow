<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\QPlan;
use App\Models\QRun;
use App\Services\EvaluateQuality;

class GeneratePlan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;

    public $timeout = 300;
    public $tries = 1;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        Log::info("GeneratePlan gestartet für Run ID {$this->runId}");

        $qPlan = QPlan::where('q_run', $this->runId)
            ->where('calculated', false)
            ->first();

        if (!$qPlan) {
            QRun::where('id', $this->runId)->update([
                'finished_at' => now(),
                'status' => 'done',
            ]);

            Log::info("Alle Pläne für Run ID {$this->runId} sind berechnet.");
            return;
        }

        $planId = $qPlan->plan;

        Log::info("Generating plan: $planId (QPlan ID {$qPlan->id})");

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
            Log::error('GeneratePlan failed', [
                'planId' => $planId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            while (ob_get_level() > $startLevel) {
                ob_end_clean();
            }
        }

        Log::info('GeneratePlan completed', ['planId' => $planId]);

        $evaluator = new EvaluateQuality();
        $evaluator->evaluatePlanId($planId);

        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);

        Log::info("Plan $planId ausgewertet, QPlan {$qPlan->id} abgehakt");

        GeneratePlan::dispatch($this->runId);
    }
}