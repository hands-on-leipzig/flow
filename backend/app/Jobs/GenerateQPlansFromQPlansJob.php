<?php

namespace App\Jobs;

use App\Services\QualityEvaluator;
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
        // Log::info("qRun {$this->newRunId}: Start asynchronous copy of qPlans");

        $qPlans = new QualityEvaluator();
        $qPlans->generateQPlansFromQPlans($this->newRunId, $this->planIds);

        Log::info("qRun {$this->newRunId}: Execution dispatched");
        
        \App\Jobs\ExecuteQPlanJob::dispatch($this->newRunId);

    }
}