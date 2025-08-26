<?php

namespace App\Services;

use App\Models\QPlan;
use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\MActivityTypeDetail; 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class EvaluateQuality
{
    // For debugging: Dump all relevant activities for a given plan TODEL
    public function debugDump(int $qPlanId): array
    {
        $activities = $this->loadActivities($qPlanId);
        return $activities->toArray(); 
    }


    /**
     * Main entry point to evaluate all quality metrics (Q1–Q4) for a given plan.
     */
    public function evaluate(int $qPlanId): void
    {
        $activities = $this->loadActivities($qPlanId);
        $qPlan = QPlan::findOrFail($qPlanId);
        
        $this->calculateQ1($qPlan, $activities);
        $this->calculateQ2($qPlan, $activities);
        $this->calculateQ3($qPlan, $activities);
        $this->calculateQ4($qPlan, $activities);
        // Q5 will be added later
    }

    /**
     * Load all relevant activities for a given plan, including joins to group and type info.
     */
    private function loadActivities(int $qPlanId): Collection
    {
        return Activity::query()
            ->select([
                'activity.*',
                'activity_group.id AS group_id',
                'activity_group.plan AS plan_id',
                'activity_group.activity_type_detail AS group_atd_id',
                'm_activity_type_detail.id AS atd_id',
                'm_activity_type_detail.name AS atd_name',
                'm_activity_type_detail.first_program'
            ])
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
            ->where('activity_group.plan', $qPlanId)
            ->where(function ($query) {
                $query->whereNull('m_activity_type_detail.first_program')
                      ->orWhere('m_activity_type_detail.first_program', 3);
            })
            ->get();
    }

    /**
     * Evaluate Q1: Check for minimum gap between the 5 relevant activities.
     */
    private function calculateQ1(QPlan $qPlan, Collection $activities): void
    {
        // TODO
    }

    /**
     * Evaluate Q2: Check how many different tables the team played on.
     */
    private function calculateQ2(QPlan $qPlan, Collection $activities): void
    {
        // TODO
    }

    /**
     * Evaluate Q3: Check how many different opponents each team had.
     */
    private function calculateQ3(QPlan $qPlan, Collection $activities): void
    {
        // TODO
    }

    /**
     * Evaluate Q4: Check if test and first match are on the same table.
     */
    private function calculateQ4(QPlan $qPlan, Collection $activities): void
    {
        // TODO
    }
}