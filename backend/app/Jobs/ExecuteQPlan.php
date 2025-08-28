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

class ExecuteQPlan implements ShouldQueue
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
        Log::info("ExecuteQPlan gestartet für Run ID {$this->runId}");

        $qPlan = QPlan::where('q_run', $this->runId)
            ->where('calculated', false)
            ->first();

        if (!$qPlan) {
            QRun::where('id', $this->runId)->update([
                'finished_at' => now(),
                'status' => 'done',
            ]);

            Log::info("Quality Run ID {$this->runId} berechnet.");
            return;
        }

        $planId = $qPlan->plan;

        Log::info("Generierung plan ID $planId für QPlan ID {$qPlan->id} startet");

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

        Log::info("Evaluierung Quality für QPlan ID {$qPlan->id} startet");

        $evaluator = new EvaluateQuality();
        $evaluator->evaluatePlanId($planId);

        // Mark QPlan as calculated
        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        Log::info("Plan $planId evaluiert, QPlan {$qPlan->id} abgehakt.");

        ExecuteQPlan::dispatch($this->runId);
    }
}