<?php
namespace App\Jobs;

use App\Services\EvaluateQuality;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteQRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ExecuteQRun gestartet für Run {$this->runId}");

        try {
            $qPlans = new EvaluateQuality();
            $qPlans->generateQPlans($this->runId);
            Log::info("ExecuteQRun erfolgreich abgeschlossen für Run {$this->runId}");
        } catch (\Throwable $e) {
            Log::error("ExecuteQRun fehlgeschlagen für Run {$this->runId}: {$e->getMessage()}");
            // Optional: Hier könnte man den q_run als 'failed' markieren
        }
    }
}
