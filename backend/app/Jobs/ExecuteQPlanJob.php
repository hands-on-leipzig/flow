<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\PlanGenerateController;
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

            Log::info("qRun {$this->runId}: done");
            return;
        }

        $planId = $qPlan->plan;

        Log::info("qPlan {$qPlan->id}: creation of plan $planId dispatched");

        $pc = new PlanGenerateController();
        $pc->generate($planId, false);   // run synchronously as part of this job

        // Log::info("qPlan {$qPlan->id}: dispatch of quality evaluation for plan $planId");

        $evaluator = new EvaluateQuality();
        $evaluator->evaluatePlanId($planId);

        // Mark QPlan as calculated
        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        Log::info("qPlan {$qPlan->id}: evaluation done");

        ExecuteQPlanJob::dispatch($this->runId);
    }
}