<?php

namespace App\Http\Controllers\External;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventController extends BaseController
{
    /**
     * List events
     */
    public function index(Request $request)
    {
        try {
            $this->requireScope($request, 'events:read');
            
            $events = Event::query()
                ->with(['regionalPartner', 'levelRel', 'seasonRel'])
                ->paginate(20);
            
            return $this->success($events);
        } catch (\Exception $e) {
            Log::error('External API - Events index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('Failed to fetch events: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get event by ID
     */
    public function show(Request $request, $id)
    {
        try {
            $this->requireScope($request, 'events:read');
            
            $event = Event::with(['regionalPartner', 'levelRel', 'seasonRel'])
                ->findOrFail($id);
            
            return $this->success($event);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Event not found', null, 404);
        } catch (\Exception $e) {
            Log::error('External API - Events show error', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('Failed to fetch event: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get event by slug
     */
    public function showBySlug(Request $request, $slug)
    {
        try {
            $this->requireScope($request, 'events:read');
            
            $event = Event::with(['regionalPartner', 'levelRel', 'seasonRel'])
                ->where('slug', $slug)
                ->firstOrFail();
            
            return $this->success($event);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Event not found', null, 404);
        } catch (\Exception $e) {
            Log::error('External API - Events showBySlug error', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('Failed to fetch event: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Update event by dolibarr/draht ID
     * Finds event by event_explore or event_challenge and updates it
     */
    public function updateByDrahtId(Request $request, $drahtId)
    {
        try {
            $this->requireScope($request, 'events:write');
            
            // Find event by event_explore or event_challenge
            $event = Event::where('event_explore', $drahtId)
                ->orWhere('event_challenge', $drahtId)
                ->first();
            
            if (!$event) {
                return $this->error('Event not found with the provided draht ID', null, 404);
            }
            
            // Validate and get updatable fields
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'slug' => 'sometimes|nullable|string|max:255',
                'date' => 'sometimes|date',
                'days' => 'sometimes|integer|min:1|max:10',
                'link' => 'sometimes|nullable|string|max:255',
                'regional_partner' => 'sometimes|integer|exists:regional_partner,id',
                'level' => 'sometimes|integer|exists:m_level,id',
                'season' => 'sometimes|integer|exists:m_season,id',
                'event_explore' => 'sometimes|nullable|integer',
                'event_challenge' => 'sometimes|nullable|integer',
                'contao_id_explore' => 'sometimes|nullable|integer',
                'contao_id_challenge' => 'sometimes|nullable|integer',
                'wifi_ssid' => 'sometimes|nullable|string|max:255',
                'wifi_password' => 'sometimes|nullable|string',
                'wifi_instruction' => 'sometimes|nullable|string',
            ]);
            
            // Update the event
            $event->update($validated);
            
            // Reload with relationships
            $event->load(['regionalPartner', 'levelRel', 'seasonRel']);
            
            Log::info('External API - Event updated by draht ID', [
                'draht_id' => $drahtId,
                'event_id' => $event->id,
                'updated_fields' => array_keys($validated)
            ]);
            
            return $this->success($event, 'Event updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', $e->errors(), 422);
        } catch (\Exception $e) {
            Log::error('External API - Events updateByDrahtId error', [
                'draht_id' => $drahtId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('Failed to update event: ' . $e->getMessage(), null, 500);
        }
    }
}

