<?php

namespace App\Jobs;

use App\Services\EvaluateQuality;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateQPlansFromQPlans implements ShouldQueue
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
        Log::info("Asynchrones Generieren von QPlans für neuen Run {$this->newRunId} gestartet (" . count($this->planIds) . " Pläne)");

        $qPlans = new EvaluateQuality();
        $qPlans->generateQPlansFromQPlans($this->newRunId, $this->planIds);

        \App\Jobs\ExecuteQPlan::dispatch($this->newRunId);
        Log::info("Job GenerateQPlansFromQPlans abgeschlossen. ExecuteQPlan für Run {$this->newRunId} wurde dispatcht.");
    }
}