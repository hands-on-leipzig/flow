<?php

namespace App\Jobs;

use App\Services\EvaluateQuality;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateQPlansFromSelection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $qRunId;

    public function __construct(int $qRunId)
    {
        $this->qRunId = $qRunId;
    }

    public function handle(): void
    {
        Log::info("Starte GenerateQPlansFromSelection für QRun ID {$this->qRunId}");

        $service = new EvaluateQuality();
        $service->generateQPlansFromSelection($this->qRunId);

        Log::info("QPlans aus Selection für QRun ID {$this->qRunId} erzeugt – starte ExecuteQPlan");
        ExecuteQPlan::dispatch($this->qRunId);
    }
}
