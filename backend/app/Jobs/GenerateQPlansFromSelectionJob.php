<?php

namespace App\Jobs;

use App\Services\EvaluateQuality;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateQPlansFromSelectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $qRunId;

    public function __construct(int $qRunId)
    {
        $this->qRunId = $qRunId;
    }

    public function handle(): void
    {
        Log::info("qRun {$this->qRunId}: Start asynchronous creation of qPlans");

        $service = new EvaluateQuality();
        $service->generateQPlansFromSelection($this->qRunId);

        Log::info("qRun {$this->qRunId}: Start asynchronous qRun");

        \App\Jobs\ExecuteQPlanJob::dispatch($this->qRunId);
    }
}
