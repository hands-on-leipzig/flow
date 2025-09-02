<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Plan;
use App\Services\GeneratePlan;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePlanJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $planId;

    public function __construct($planId)
    {
        $this->planId = $planId;
    }

    public function handle()
    {
        $plan = Plan::find($this->planId);
        if (!$plan) return;

        $plan->generator_status = 'running';
        $plan->save();

        GeneratePlan::run($plan->id);

        $plan->generator_status = 'done';
        $plan->save();
    }
}
