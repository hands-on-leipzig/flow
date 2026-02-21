<?php

namespace App\Services;

use App\Http\Controllers\Api\PublishController;
use App\Models\Slide;

class SlideGeneratorService
{
    private array $defaultBackgroundImage = [
        'backgroundImage' => [
            'type' => 'Image',
            'version' => '6.7.1',
            'left' => 0,
            'top' => -3.3333,
            'width' => 1920,
            'height' => 1096,
            'scaleX' => 0.4167,
            'scaleY' => 0.4167,
            'originX' => 'left',
            'originY' => 'top',
            'src' => '/background.png',
        ],
    ];

    public function __construct(private readonly PublishController $publishController)
    {
    }

    public function generatePublicPlanSlide($planId, $slideshowId, $next = false, $order = 0)
    {
        $type = $next ? 'PublicPlanNextSlideContent' : 'PublicPlanSlideContent';
        $text = $next ? 'Als nächstes' : 'Aktuell läuft';
        $name = $next ? 'Zeitplan - Als nächstes' : 'Zeitplan - Jetzt';

        $contentArray = [
            'background' => $this->generatePublicPlanBackground($text),
            'planId' => (int)$planId,
        ];

        return $this->createSlide($name, $slideshowId, $type, json_encode($contentArray), $order);
    }

    private function generatePublicPlanBackground(string $textContent): array
    {
        $background = $this->generateDefaultBackground();

        $background['objects'] = [
            [
                'type' => 'Textbox',
                'version' => '6.7.1',
                'left' => 150,
                'top' => 15,
                'width' => 500,
                'height' => 40,
                'fontSize' => 30,
                'fontFamily' => 'Uniform',
                'textAlign' => 'center',
                'originX' => 'left',
                'originY' => 'top',
                'text' => $textContent,
            ],
        ];

        return $background;
    }

    public function generateDefaultBackground(): array
    {
        return [
            'version' => '6.7.1',
            ...$this->defaultBackgroundImage
        ];
    }

    public function generateQRCodeSlide($eventId, $slideshowId, $order)
    {
        $contentArray = [
            'background' => $this->generateQRCodeSlideBackground($eventId),
        ];

        return $this->createSlide('QR-Code zum Zeitplan', $slideshowId, 'FabricSlideContent', json_encode($contentArray), $order);
    }

    private function generateQRCodeSlideBackground($eventId): array
    {
        $qrCode = $this->publishController->linkAndQRcode($eventId)->getData()->qrcode;

        $background = $this->generateDefaultBackground();

        $background['objects'] = [
            [
                'type' => 'Image',
                'version' => '6.7.1',
                'left' => 290,
                'top' => 135,
                'width' => 320,
                'height' => 320,
                'scaleX' => 0.7031,
                'scaleY' => 0.7031,
                'originX' => 'left',
                'originY' => 'top',
                'src' => $qrCode,
            ],
            [
                'type' => 'Textbox',
                'version' => '6.7.1',
                'left' => 290,
                'top' => 370,
                'width' => 220,
                'height' => 30,
                'fontSize' => 25,
                'fontFamily' => 'Uniform',
                'textAlign' => 'center',
                'originX' => 'left',
                'originY' => 'top',
                'text' => 'zum Zeitplan',
            ],
        ];

        return $background;
    }

    public function generateRobotGameResultsSlide($slideshowId, $order)
    {
        $contentArray = ['background' => $this->generateDefaultBackground()];
        return $this->createSlide("Robot-Game Ergebnisse", $slideshowId, 'RobotGameSlideContent', json_encode($contentArray), $order);
    }

    private function createSlide($name, $slideshowId, $type, $contentJson, $order)
    {
        return Slide::create([
            'name' => $name,
            'slideshow_id' => $slideshowId,
            'type' => $type,
            'content' => $contentJson,
            'order' => $order,
            'active' => 1,
        ]);
    }
}
