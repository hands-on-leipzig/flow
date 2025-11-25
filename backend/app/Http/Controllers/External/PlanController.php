<?php

namespace App\Http\Controllers\External;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends BaseController
{
    /**
     * Get plan by event ID
     */
    public function showByEvent(Request $request, $eventId)
    {
        $this->requireScope($request, 'plans:read');
        
        $plan = Plan::where('event', $eventId)
            ->with(['event'])
            ->firstOrFail();
        
        return $this->success($plan);
    }
    
    /**
     * Get plan by ID
     */
    public function show(Request $request, $id)
    {
        $this->requireScope($request, 'plans:read');
        
        $plan = Plan::with(['event'])
            ->findOrFail($id);
        
        return $this->success($plan);
    }
    
    /**
     * Get plan activities
     */
    public function activities(Request $request, $id)
    {
        $this->requireScope($request, 'plans:read');
        
        $plan = Plan::findOrFail($id);
        
        // TODO: Implement activities retrieval
        // This is a placeholder - implement based on your needs
        
        return $this->success([
            'plan_id' => $plan->id,
            'activities' => [], // Placeholder
        ]);
    }
}

