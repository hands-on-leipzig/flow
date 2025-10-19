<?php

namespace App\Jobs;

use App\Services\QualityEvaluatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateQPlansFromQPlansJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $newRunId;
    protected array $planIds;

    public function __construct(int $newRunId, array $planIds)
    {
        $this->newRunId = $newRunId;
        $this->planIds = $planIds;
    }

    public function handle(): void
    {
        Log::info("GenerateQPlansFromQPlansJob: Starting qRun {$this->newRunId}", [
            'plan_count' => count($this->planIds),
        ]);

        $qPlans = new QualityEvaluatorService();
        $qPlans->generateQPlansFromQPlans($this->newRunId, $this->planIds);
        
        \App\Jobs\ExecuteQPlanJob::dispatch($this->newRunId);
    }
}