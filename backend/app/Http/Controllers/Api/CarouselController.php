<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use App\Models\SlideShow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery\Exception;


class CarouselController extends Controller
{

    public function __construct(private readonly PublishController $publishController)
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

        $slideshow = $res->slideshow;
        if (!is_object($slideshow)) {
            $slideshow = SlideShow::with('event')->find($slideshow);
        }
        $this->hasEventAccessOrThrow($slideshow->event);

        return response()->json($res);
    }

    public function updateSlide(Request $request, $slide)
    {
        $data = $this->onlySlide($request, false);

        $slideModel = Slide::findOrFail($slide);

        $slideshow = $slideModel->slideshow;
        if (!is_object($slideshow)) {
            $slideshow = SlideShow::with('event')->find($slideshow);
        }
        $this->hasEventAccessOrThrow($slideshow->event);

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

        $slideshow = $slide->slideshow;
        if (!is_object($slideshow)) {
            $slideshow = SlideShow::with('event')->find($slideshow);
        }
        $this->hasEventAccessOrThrow($slideshow->event);

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
            $providedContent['background'] = json_decode($this->generatePublicPlanBackground());
            $data['content'] = json_encode($providedContent);
        } else {
            $data['content'] = '{"background": ' . $this->generatePublicPlanBackground() . '}';
        }
        $data['slideshow'] = $slideshowId;

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

    private string $defaultBackgroundImage = "\"backgroundImage\":{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":0,\"top\":-3.3333,\"width\":1920,\"height\":1096,\"scaleX\":0.4167,\"scaleY\":0.4167,\"src\":\"/background.png\"}";

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

            $slide1 = $this->generatePublicPlanSlide($planId, $slideshow->id);
            $slide2 = $this->generateQRCodeSlide($eventId, $slideshow->id);

            $slideshow->slides = [$slide1, $slide2];

            return response()->json(['success' => true, 'slideshow' => $slideshow]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // TODO: Diesen Code in einen eigenen Service verschieben? Dann kann der noch mehr slide-Vorlagen anbieten, nicht nur die Standard-Slides generieren

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

    private function generateQRCodeSlide($eventId, $slideshowId)
    {
        $content = '{"background": ' . $this->generateQRCodeSlideBackground($eventId) . '}';

        $slide = Slide::create([
            'name' => 'Zeitplan-QR-Code',
            'slideshow' => $slideshowId,
            'type' => 'FabricSlideContent',
            'content' => $content,
            'order' => 1,
        ]);

        return $slide;
    }

    private function generateQRCodeSlideBackground($eventId)
    {
        $qrCode = $this->publishController->linkAndQRcode($eventId)->getData()->qrcode;

        $qrCodeSlideBackground = "{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage
            . ",\"objects\":[{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":290,\"top\":135,\"width\":320,\"height\":320,\"scaleX\":0.7031,\"scaleY\":0.7031,\"src\":\"" . $qrCode . "\"}]"
            . "}";

        return json_encode($qrCodeSlideBackground);
    }

}
