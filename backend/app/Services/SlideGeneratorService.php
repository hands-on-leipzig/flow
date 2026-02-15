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

    public function generatePublicPlanSlide($planId, $slideshowId, $next = false, $order = 0)
    {
        $type = $next ? 'PublicPlanNextSlideContent' : 'PublicPlanSlideContent';
        $text = $next ? 'Als nächstes' : 'Aktuell läuft';
        $name = $next ? 'Zeitplan - Als nächstes' : 'Zeitplan - Jetzt';
        $content = '{ "background": ' . $this->generatePublicPlanBackground($text)
            . ', "planId": ' . $planId
            . '}';

        return $this->createSlide($name, $slideshowId, $type, $content, $order);
    }

    public function generatePublicPlanBackground($textContent)
    {
        $background = "{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage
            . ",\"objects\":[{\"type\":\"Textbox\",\"version\":\"6.7.1\",\"left\":150,\"top\":15,\"width\":500,\"height\":40,"
            . "\"fontSize\":30,\"fontFamily\":\"Uniform\",\"textAlign\":\"center\",\"text\":\"" . $textContent . "\"}]"
            . "}";

        return json_encode($background);
    }

    public function generateDefaultBackground()
    {
        return json_encode("{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage . "}");
    }

    public function generateQRCodeSlide($eventId, $slideshowId, $order)
    {
        $content = '{"background": ' . $this->generateQRCodeSlideBackground($eventId) . '}';
        return $this->createSlide('QR-Code zum Zeitplan', $slideshowId, 'FabricSlideContent', $content, $order);
    }

    private function generateQRCodeSlideBackground($eventId)
    {
        $qrCode = $this->publishController->linkAndQRcode($eventId)->getData()->qrcode;

        $qrCodeSlideBackground = "{\"version\":\"6.7.1\"," . $this->defaultBackgroundImage
            . ",\"objects\":[{\"type\":\"Image\",\"version\":\"6.7.1\",\"left\":290,\"top\":135,\"width\":320,\"height\":320,\"scaleX\":0.7031,\"scaleY\":0.7031,\"src\":\"" . $qrCode . "\"}]"
            . "}";

        return json_encode($qrCodeSlideBackground);
    }

    public function generateRobotGameResultsSlide($slideshowId, $order)
    {
        $content = '{"background": ' . $this->generateDefaultBackground() . '}';
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
