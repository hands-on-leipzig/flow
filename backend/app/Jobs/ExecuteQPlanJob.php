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
use App\Services\PlanGeneratorService;
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

            Log::info("ExecuteQPlanJob: qRun {$this->runId} completed");
            return;
        }

        $planId = $qPlan->plan;
        Log::info("ExecuteQPlanJob: Processing qPlan {$qPlan->id}", [
            'q_run' => $this->runId,
            'plan_id' => $planId,
            'first_program' => $qPlan->first_program,
            'c_teams' => $qPlan->c_teams,
            'j_lanes' => $qPlan->j_lanes,
            'r_tables' => $qPlan->r_tables,
        ]);

        $generator = new PlanGeneratorService();

        try {
            $support = $generator->isSupported($planId);
            if (! ($support['supported'] ?? false)) {
                Log::warning('ExecuteQPlanJob: plan not supported, skipping generate', [
                    'q_plan' => $qPlan->id,
                    'plan_id' => $planId,
                    'error' => $support['error'] ?? null,
                    'details' => $support['details'] ?? null,
                ]);
            } else {
                $generator->prepare($planId, 'job', null);
                // Run synchronously so evaluation sees matches before we mark calculated.
                $generator->run($planId, true);
            }
        } catch (\Throwable $e) {
            Log::error('ExecuteQPlanJob: generate/evaluate failed', [
                'q_plan' => $qPlan->id,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
        }

        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        ExecuteQPlanJob::dispatch($this->runId);
    }
}
