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
use App\Services\PlanGenerator;
use App\Services\QualityEvaluator;
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
        // Mark run as running
        QRun::where('id', $this->runId)->update([
            'status' => 'running',
        ]);

        // Nächstes QPlan holen, das noch nicht berechnet ist
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
        Log::info("qPlan {$qPlan->id}: generation of plan {$planId} started");

        // Plan erzeugen über den Service
        $generator = new PlanGenerator();
        $generator->prepare($planId);
        $generator->dispatchJob($planId, true);

        // Warten

        // QPlan als berechnet markieren
        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        // Job erneut dispatchen, bis alle QPlans berechnet sind
        ExecuteQPlanJob::dispatch($this->runId);
    }
}