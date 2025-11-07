<?php

namespace App\Services;

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
            . ', "background": ' . $this->generateStandardBackground()
            . ', "planId": ' . $planId
            . '}';

        return $this->createSlide('Öffentlicher Zeitplan', $slideshowId, 'PublicPlanSlideContent', $content, 0);
    }

    public function generateStandardBackground()
    {
        return json_encode("{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage . "}");
    }

    public function generateQRCodeSlide($eventId, $slideshowId)
    {
        $content = '{"background": ' . $this->generateQRCodeSlideBackground($eventId) . '}';
        return $this->createSlide('Zeitplan-QR-Code', $slideshowId, 'FabricSlideContent', $content, 1);
    }

    private function generateQRCodeSlideBackground($eventId)
    {
        $qrCode = $this->publishController->linkAndQRcode($eventId)->getData()->qrcode;

        $qrCodeSlideBackground = "{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage
            . ",\"objects\":[{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":290,\"top\":135,\"width\":320,\"height\":320,\"scaleX\":0.7031,\"scaleY\":0.7031,\"src\":\"" . $qrCode . "\"}]"
            . "}";

        return json_encode($qrCodeSlideBackground);
    }

    public function generateRobotGameResultsSlide($slideshowId, $order = 2)
    {
        $content = '{"background": ' . $this->generateStandardBackground() . '}';
        return $this->createSlide("Robot-Game Ergebnisse", $slideshowId, 'RobotGameSlideContent', $content, $order);
    }

    private function createSlide($name, $slideshowId, $type, $content, $order)
    {
        return Slide::create([
            'name' => $name,
            'slideshow_id' => $slideshowId,
            'type' => $type,
            'content' => $content,
            'order' => $order,
            'active' => 1,
        ]);
    }

}
