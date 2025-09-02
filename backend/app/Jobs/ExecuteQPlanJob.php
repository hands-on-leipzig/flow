<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\PlanController;
use App\Models\QPlan;
use App\Models\QRun;
use App\Services\EvaluateQuality;
use Carbon\Carbon;

class ExecuteQPlanJob implements ShouldQueue
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

        QRun::where('id', $this->runId)->update([
                'status' => 'running',
            ]);

        $qPlan = QPlan::where('q_run', $this->runId)
            ->where('calculated', false)
            ->first();

        if (!$qPlan) {
            QRun::where('id', $this->runId)->update([
                'finished_at' => Carbon::now(),
                'status' => 'done',
            ]);

            Log::info("qRun {$this->runId} done.");
            return;
        }

        $planId = $qPlan->plan;
/*
        $startLevel = ob_get_level();
        ob_start();
*/
        Log::info("qPlan {$qPlan->id}: dispatch creation of plan $planId");

        $pc = new PlanController();
        $pc->generate($planId, true);

/*
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
        }      */

        Log::info("qPlan {$qPlan->id}: dispatch of quality evaluation for plan $planId");

        $evaluator = new EvaluateQuality();
        $evaluator->evaluatePlanId($planId);

        // Mark QPlan as calculated
        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        Log::info("qPlan {$qPlan->id}: evaluation done");

        ExecuteQPlanJob::dispatch($this->runId);
    }
}