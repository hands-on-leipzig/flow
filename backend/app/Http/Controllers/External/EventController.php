<?php

namespace App\Http\Controllers\External;

use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends BaseController
{
    /**
     * List events
     */
    public function index(Request $request)
    {
        $this->requireScope($request, 'events:read');
        
        $events = Event::query()
            ->with(['regionalPartner', 'level', 'season'])
            ->paginate(20);
        
        return $this->success($events);
    }
    
    /**
     * Get event by ID
     */
    public function show(Request $request, $id)
    {
        $this->requireScope($request, 'events:read');
        
        $event = Event::with(['regionalPartner', 'level', 'season'])
            ->findOrFail($id);
        
        return $this->success($event);
    }
    
    /**
     * Get event by slug
     */
    public function showBySlug(Request $request, $slug)
    {
        $this->requireScope($request, 'events:read');
        
        $event = Event::with(['regionalPartner', 'level', 'season'])
            ->where('slug', $slug)
            ->firstOrFail();
        
        return $this->success($event);
    }
}

