<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use App\Models\SlideShow;
use Illuminate\Http\Request;


class CarouselController extends Controller
{

    // Public Endpoint
    public function getPublicSlideshowForEvent($eventId)
    {
        // TODO slideshow selection logic
        // only active slides
        $slideshows = SlideShow::where('event', $eventId)
            ->with(['slides' => function ($query) {
                $query->where('active', true);
            }])
            ->get();

        return response()->json($slideshows);
    }

    // Authenticated Endpoint, includes hidden slides:
    public function getAllSlideshows($eventId)
    {
        $slideshows = SlideShow::where('event', $eventId)
            ->with('slides')
            ->get();

        return response()->json($slideshows);
    }

    public function getSlide($slide)
    {
        $res = Slide::with('slideshow')
            ->findOrFail($slide);

        // TODO Event check

        return response()->json($res);
    }

    public function updateSlide(Request $request, $slide)
    {
        $updatableFields = [
            'name',
            'type',
            'content',
            'active',
        ];

        $data = $request->only($updatableFields);

        $slideModel = Slide::findOrFail($slide);

        // TODO: Event and rights check

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

        // TODO access right checks

        foreach ($slideIds as $order => $slideId) {
            Slide::where('id', $slideId)
                ->where('slideshow', $slideshowId)
                ->update(['order' => $order]);
        }

        return response()->json(['success' => true]);
    }

    public function deleteSlide(Request $request, $slideId)
    {
        $slide = Slide::find($slideId);
        if (!$slide) {
            return response()->json(['error' => 'Slide nicht gefunden'], 404);
        }

        // TODO: Rechteprüfung

        $slide->delete();

        return response()->json(['success' => true]);
    }

    public function addSlide(Request $request, $slideshowId)
    {
        $fields = [
            'name',
            'type',
            'content',
            'order',
        ];

        $data = $request->only($fields);
        $data['slideshow'] = $slideshowId;

        // TODO Validierung (Type korrekt) und Rechte-Check

        $slide = Slide::create($data);

        return response()->json(['success' => true, 'slide' => $slide]);
    }

    private string $defaultSlideBackground = "{\"version\":\"6.7.1\",\"backgroundImage\":{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":0,\"top\":-3.3333,\"width\":1920,\"height\":1096,\"scaleX\":0.4167,\"scaleY\":0.4167,\"src\":\"/background.png\"}}";

    public function generateSlideshow(Request $request, $eventId)
    {
        // TODO Eventid prüfen

        $planId = $request->input('planId');
        if (!$planId || !is_numeric($planId)) {
            return response()->json(['error' => 'plan id required'], 400);
        }

        $slideshow = SlideShow::create([
            'event' => $eventId,
            'name' => 'Standard-Slideshow',
            'transition_time' => 15,
        ]);

        $content = '{ "hours": 2'
            . ', "background": ' . json_encode($this->defaultSlideBackground)
            . ', "planId": ' . $planId
            . '}';

        $slide = Slide::create([
            'name' => 'Öffentlicher Zeitplan',
            'slideshow' => $slideshow->id,
            'type' => 'PublicPlanSlideContent',
            'content' => $content,
            'order' => 0,
        ]);

        $slideshow->slides = [$slide];

        return response()->json(['success' => true, 'slideshow' => $slideshow]);

    }

}
