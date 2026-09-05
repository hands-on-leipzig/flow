<?php

namespace App\Http\Controllers\External;

use App\Services\EventSlugService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Central slug registry for the one-link. Callers ask with their own event id and the
 * system that id belongs to (`flow`, `draht`; `join` resolves through DRAHT ids) and
 * get back the slug plus the ready URL, so no system has to store one itself.
 */
class SlugController extends BaseController
{
    public function __construct(private readonly EventSlugService $slugs) {}

    /**
     * GET /api/external/slugs/{system}/{eventId}
     */
    public function show(Request $request, string $system, int $eventId)
    {
        $this->requireScope($request, 'events:read');

        if ($this->slugs->normalizeSystem($system) === null) {
            return $this->error("Unknown system '{$system}'. Use 'flow' or 'draht'.", null, 422);
        }

        $event = $this->slugs->resolve($system, $eventId);
        if (! $event) {
            return $this->error('Event not found for the given system and id', null, 404);
        }

        return $this->success($this->slugs->describe($event));
    }

    /**
     * POST /api/external/slugs/{system}/{eventId}
     *
     * Creates the slug when the event has none yet and returns the existing one
     * otherwise, so callers can be dumb about whether a link was generated already.
     */
    public function ensure(Request $request, string $system, int $eventId)
    {
        $this->requireScope($request, 'events:write');

        if ($this->slugs->normalizeSystem($system) === null) {
            return $this->error("Unknown system '{$system}'. Use 'flow' or 'draht'.", null, 422);
        }

        $event = $this->slugs->resolve($system, $eventId);
        if (! $event) {
            return $this->error('Event not found for the given system and id', null, 404);
        }

        try {
            $created = empty($event->slug);
            $this->slugs->ensure($event);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            Log::error('External API - slug ensure failed', [
                'system' => $system,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to create slug', null, 500);
        }

        return $this->success(
            $this->slugs->describe($event->refresh()),
            $created ? 'Slug created' : 'Slug already present'
        );
    }

    /**
     * PUT /api/external/slugs/{system}/{eventId}
     *
     * Sets the slug by hand and marks it as manual, which keeps the generator from
     * replacing it later. The previous slug stays redirectable.
     */
    public function update(Request $request, string $system, int $eventId)
    {
        $this->requireScope($request, 'events:write');

        if ($this->slugs->normalizeSystem($system) === null) {
            return $this->error("Unknown system '{$system}'. Use 'flow' or 'draht'.", null, 422);
        }

        $validated = $request->validate([
            'slug' => 'required|string|max:255',
        ]);

        $event = $this->slugs->resolve($system, $eventId);
        if (! $event) {
            return $this->error('Event not found for the given system and id', null, 404);
        }

        try {
            $this->slugs->assign($event, $validated['slug'], true);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            Log::error('External API - slug update failed', [
                'system' => $system,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to update slug', null, 500);
        }

        return $this->success($this->slugs->describe($event->refresh()), 'Slug updated');
    }

    /**
     * GET /api/external/slugs/lookup?slug=aachen[&year=2025]
     *
     * Without a year the current season answers. `redirect_to` is set when the slug is
     * an earlier name of the event.
     */
    public function lookup(Request $request)
    {
        $this->requireScope($request, 'events:read');

        $validated = $request->validate([
            'slug' => 'required|string|max:255',
            'year' => 'nullable|integer|min:1990|max:2999',
        ]);

        $match = $this->slugs->find(
            $validated['slug'],
            isset($validated['year']) ? (int) $validated['year'] : null
        );

        if (! $match) {
            return $this->error('No event for this slug', null, 404);
        }

        return $this->success(
            $this->slugs->describe($match['event']) + ['redirect_to' => $match['redirect_to']]
        );
    }
}
