<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use App\Models\SlideShow;
use App\Services\SlideGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery\Exception;


class CarouselController extends Controller
{

    public function __construct(private readonly SlideGeneratorService $slideGeneratorService)
    {
    }

    // Public Endpoint
    public function getPublicSlideshowForEvent($event)
    {
        // TODO slideshow selection logic
        // only active slides
        $slideshows = SlideShow::where('event', $event->id)
            ->with(['slides' => function ($query) {
                $query->where('active', true);
            }])
            ->get();

        return response()->json($slideshows);
    }

    private function hasEventAccess($eventId): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $roles = $user->getRoles() ?? [];
        // This should be an util method
        $isAdmin = in_array('flow-admin', $roles) || in_array('flow_admin', $roles);
        if ($isAdmin) {
            return true;
        }

        // If the user is a regional partner for the passed event, he may proceed
        $hasEvent = $user->regionalPartners()
            ->whereHas('events', function ($query) use ($eventId) {
                $query->where('id', $eventId);
            })
            ->exists();
        return $hasEvent;
    }

    private function hasEventAccessOrThrow($eventId): void
    {
        if (!$this->hasEventAccess($eventId)) {
            abort(response()->json(['error' => 'unauthorized'], 401));
        }
    }

    // Authenticated Endpoint, includes hidden slides:
    public function getAllSlideshows($event)
    {
        $this->hasEventAccessOrThrow($event->id);

        $slideshows = SlideShow::where('event', $event->id)
            ->with('slides')
            ->get();

        return response()->json($slideshows);
    }

    public function getSlide($slide)
    {
        $res = Slide::with('slideshow')
            ->findOrFail($slide);

        $this->hasEventAccessOrThrow($res->slideshow->event);

        return response()->json($res);
    }

    public function updateSlide(Request $request, $slide)
    {
        $data = $this->onlySlide($request, false);

        $slideModel = Slide::findOrFail($slide);

        $this->hasEventAccessOrThrow($slideModel->slideshow->event);

        $slideModel->update($data);
        return response()->json(['success' => true]);
    }

    public function updateSlideshowOrder($slideshowId, Request $request)
    {
        $slideIds = $request->input('slide_ids');
        if (!is_array($slideIds) || empty($slideIds)) {
            return response()->json(['error' => 'slide_ids array required'], 400);
        }

        $slideshow = SlideShow::findOrFail($slideshowId);
        if (!$slideshow) {
            return response()->json(['error' => 'Slideshow not found for event'], 404);
        }

        $this->hasEventAccessOrThrow($slideshow->event);

        foreach ($slideIds as $order => $slideId) {
            Slide::where('id', $slideId)
                ->whereBelongsTo($slideshow)
                ->update(['order' => $order]);
        }

        return response()->json(['success' => true]);
    }

    public function updateSlideshow(Request $request, $slideshowId)
    {
        $fields = [
            'name',
            'transition_time',
        ];

        $data = $request->only($fields);

        $slideshow = SlideShow::findOrFail($slideshowId);

        $this->hasEventAccessOrThrow($slideshow->event);

        $slideshow->update($data);
        return response()->json(['success' => true]);
    }

    public function deleteSlide(Request $request, $slideId)
    {
        $slide = Slide::find($slideId);
        if (!$slide) {
            return response()->json(['error' => 'Slide nicht gefunden'], 404);
        }

        $this->hasEventAccessOrThrow($slide->slideshow->event);

        $slide->delete();

        return response()->json(['success' => true]);
    }

    public function addSlide(Request $request, $slideshowId)
    {
        $slideshow = SlideShow::findOrFail($slideshowId);
        $this->hasEventAccessOrThrow($slideshow->event);

        $data = $this->onlySlide($request, true);
        // Hintergrund hinzufügen, aber content nicht überschreiben
        $providedContent = $data['content'] ?? null;
        if ($providedContent) {
            $providedContent = json_decode($providedContent, true);
            $providedContent['background'] = json_decode($this->slideGeneratorService->generateStandardBackground());
            $data['content'] = json_encode($providedContent);
        } else {
            $data['content'] = '{"background": ' . $this->slideGeneratorService->generateStandardBackground() . '}';
        }
        $data['slideshow_id'] = $slideshowId;

        // TODO Input-Validierung (Type korrekt, etc.)

        $slide = Slide::create($data);

        return response()->json(['success' => true, 'slide' => $slide]);
    }

    private function onlySlide(Request $request, bool $allowOrder)
    {
        $fields = [
            'name',
            'type',
            'content',
            'active',
        ];

        if ($allowOrder) {
            $fields[] = 'order';
        }

        return $request->only($fields);
    }

    public function generateSlideshow(Request $request, $event)
    {
        $eventId = $event->id;

        $this->hasEventAccessOrThrow($eventId);

        $planId = $request->input('planId');
        if (!$planId || !is_numeric($planId)) {
            return response()->json(['error' => 'plan id required'], 400);
        }

        try {
            $slideshow = SlideShow::create([
                'event' => $eventId,
                'name' => 'Standard-Slideshow',
                'transition_time' => 5,
            ]);

            $slide1 = $this->slideGeneratorService->generatePublicPlanSlide($planId, $slideshow->id);
            $slide2 = $this->slideGeneratorService->generateQRCodeSlide($eventId, $slideshow->id);
            $slide3 = $this->slideGeneratorService->generateRobotGameResultsSlide($slideshow->id);

            $slideshow->slides = [$slide1, $slide2, $slide3];

            return response()->json(['success' => true, 'slideshow' => $slideshow]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
