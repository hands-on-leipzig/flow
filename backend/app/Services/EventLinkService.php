<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;

class EventLinkService
{
    /**
     * Generate and save link and QR code for an event
     */
    public function generateLinkAndQRCode(int $eventId): array
    {
        try {
            // Get event data
            $event = DB::table('event')
                ->where('id', $eventId)
                ->first();

            if (!$event) {
                throw new \Exception("Event not found with ID: {$eventId}");
            }

            // If link already exists, return existing data
            if (!empty($event->link) && !empty($event->qrcode) && !empty($event->slug)) {
                return [
                    'link' => $event->link,
                    'qrcode' => 'data:image/png;base64,' . $event->qrcode,
                    'slug' => $event->slug
                ];
            }

            // Get regional partner data
            $region = DB::table('regional_partner')
                ->where('id', $event->regional_partner)
                ->value('region');

            if (!$region) {
                throw new \Exception("Region not found for event {$eventId}");
            }

            // Generate slug based on level and region
            $slug = $this->generateSlug($event, $region);
            $link = config('app.url') . "/" . $slug;

            // Generate QR code
            $qrCodeRaw = $this->generateQRCode($link);

            // Save to database
            DB::table('event')
                ->where('id', $eventId)
                ->update([
                    'slug' => $slug,
                    'link' => $link,
                    'qrcode' => $qrCodeRaw,
                ]);

            Log::info("Generated link and QR code for event {$eventId}", [
                'slug' => $slug,
                'link' => $link
            ]);

            return [
                'link' => $link,
                'qrcode' => 'data:image/png;base64,' . $qrCodeRaw,
                'slug' => $slug
            ];

        } catch (\Exception $e) {
            Log::error("Failed to generate link and QR code for event {$eventId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate slug based on event level and region
     */
    private function generateSlug($event, string $region): string
    {
        switch ($event->level) {
            case 1:
                $link = $region;

                // Check if multiple events exist for this regional partner
                $eventCount = DB::table('event')
                    ->where('regional_partner', $event->regional_partner)
                    ->where('level', 1)
                    ->count();

                if ($eventCount > 1) {
                    if (!is_null($event->event_challenge)) {
                        $link .= "-challenge";
                    }
                    if (!is_null($event->event_explore)) {
                        $link .= "-explore";
                    }
                }
                break;

            case 2:
                $link = "quali-" . $region;
                break;

            case 3:
                $link = "finale"; // Region intentionally omitted
                break;

            default:
                $link = $region;
        }

        // Clean the link
        $link = trim(strtolower($link));
        $link = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', '/', ' '],
            ['ae', 'oe', 'ue', 'AE', 'OE', 'UE', 'ss', '-', '-'],
            $link
        );

        return $link;
    }

    /**
     * Generate QR code for the given URL
     */
    private function generateQRCode(string $url): string
    {
        $qrCode = new QrCode(
            $url,
            new Encoding('UTF-8'),
            ErrorCorrectionLevel::High,
            300,
            10,
            RoundBlockSizeMode::Margin,
            new Color(0, 0, 0),        // black
            new Color(255, 255, 255)   // white
        );

        $writer = new PngWriter();

        // Optional logo (currently disabled)
        $logo = null;
        $logoPath = public_path("flow/hot_outline.png");
        if (file_exists($logoPath)) {
            // Logo logic can be added here if needed
        }

        // Generate QR code
        $result = $writer->write($qrCode, $logo);
        return base64_encode($result->getString());
    }
}
