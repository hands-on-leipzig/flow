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
        $data = $this->onlySlide($request, false);

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

    public function updateSlideshow(Request $request, $slideshowId)
    {
        $fields = [
            'name',
            'transition_time',
        ];

        $data = $request->only($fields);

        $slideshow = SlideShow::findOrFail($slideshowId);

        // TODO: Event and rights check

        $slideshow->update($data);

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
        $data = $this->onlySlide($request, true);
        $data['slideshow'] = $slideshowId;

        // TODO Validierung (Type korrekt) und Rechte-Check

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

    private string $defaultBackgroundImage = "\"backgroundImage\":{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":0,\"top\":-3.3333,\"width\":1920,\"height\":1096,\"scaleX\":0.4167,\"scaleY\":0.4167,\"src\":\"/background.png\"}";

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
            'transition_time' => 5,
        ]);

        $slide1 = $this->generatePublicPlanSlide($planId, $slideshow->id);
        $slide2 = $this->generateQRCodeSlide($planId, $slideshow->id);

        $slideshow->slides = [$slide1, $slide2];

        return response()->json(['success' => true, 'slideshow' => $slideshow]);
    }

    private function generatePublicPlanSlide($planId, $slideshowId)
    {
        $content = '{ "hours": 2'
            . ', "background": ' . $this->generatePublicPlanBackground()
            . ', "planId": ' . $planId
            . '}';

        $slide = Slide::create([
            'name' => 'Öffentlicher Zeitplan',
            'slideshow' => $slideshowId,
            'type' => 'PublicPlanSlideContent',
            'content' => $content,
            'order' => 0,
        ]);

        return $slide;
    }

    private function generatePublicPlanBackground()
    {
        return json_encode("{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage . "}");
    }

    private function generateQRCodeSlide($planId, $slideshowId)
    {
        $content = '{"background": ' . $this->generateQRCodeSlideBackground($planId) . '}';

        $slide = Slide::create([
            'name' => 'Zeitplan-QR-Code',
            'slideshow' => $slideshowId,
            'type' => 'FabricSlideContent',
            'content' => $content,
            'order' => 1,
        ]);

        return $slide;
    }

    private function generateQRCodeSlideBackground($planId)
    {
        $qrCode = $this->publishController->linkAndQRcode($planId)->getData()->qrcode;

        $qrCodeSlideBackground = "{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage
            . ",\"objects\":[{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":290,\"top\":135,\"width\":320,\"height\":320,\"scaleX\":0.7031,\"scaleY\":0.7031,\"src\":\"" . $qrCode . "\"}]"
            . "}";

        return json_encode($qrCodeSlideBackground);
    }

}
