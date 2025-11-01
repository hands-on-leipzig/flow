<?php

namespace app\Services;

use App\Http\Controllers\Api\PublishController;
use App\Models\Slide;

class SlideGeneratorService
{
    private string $defaultBackgroundImage = "\"backgroundImage\":{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":0,\"top\":-3.3333,\"width\":1920,\"height\":1096,\"scaleX\":0.4167,\"scaleY\":0.4167,\"src\":\"/background.png\"}";

    public function __construct(private readonly PublishController $publishController)
    {
    }

    public function generatePublicPlanSlide($planId, $slideshowId)
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

    public function generateQRCodeSlide($eventId, $slideshowId)
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
