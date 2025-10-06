<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\ActivityFetcherService;
use App\Services\PdfLayoutService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

use Carbon\Carbon;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;


use Barryvdh\DomPDF\Facade\Pdf;        // composer require barryvdh/laravel-dompdf


class PublishController extends Controller
{
    private ActivityFetcherService $fetcher;

    public function __construct(ActivityFetcherService $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    public function linkAndQRcode(int $eventId): JsonResponse
    {
        // Event direkt laden
        $event = DB::table('event')
            ->where('id', $eventId)
            ->first();

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }


        // Wenn bereits gesetzt → zurückgeben
        if (!empty($event->link) && !empty($event->qrcode) && !empty($event->slug)) {
            return response()->json([
                'link' => $event->link,
                'qrcode' => 'data:image/png;base64,' . $event->qrcode,
            ]);
        }
    
        $region = DB::table('regional_partner')
            ->where('id', $event->regional_partner)
            ->value('region');

        if (!$region) {
            return response()->json(['error' => 'Region not found'], 404);
        }

        switch ($event->level) {

            case 1:

                $link =  $region;

                // Prüfen, ob mehrere Regio für diesen Regionalpartner existieren
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
                $link = "quali-". $region;
                break;

            case 3:
              $link = "finale"; // Region bewusst weggelassen
        }

        // Link "säubern"
        $link = trim(strtolower($link));
        $link = str_replace(array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', '/', ' '), array('ae', 'oe', 'ue', 'AE', 'OE', 'UE', 'ss', '-', '-'), $link);

        $slug = $link;
        $link = config('app.url') . "/" . $link;


        // QR-Code mit Endroid erzeugen
        $qrCode = new QrCode(
            $link,
            new Encoding('UTF-8'),
            ErrorCorrectionLevel::High,
            300,
            10,
            RoundBlockSizeMode::Margin,
            new Color(0, 0, 0),        // schwarz
            new Color(255, 255, 255)   // weiß
        );

        $writer = new PngWriter();

        // Logo optional hinzufügen
        $logo = null;
        $logoPath = public_path("flow/hot_outline.png");
        if (file_exists($logoPath)) {
            $logo = new Logo($logoPath, 100); // 50px breit
        }

        // QR-Code schreiben
        $result = $writer->write($qrCode, $logo);
        $qrcodeRaw = base64_encode($result->getString()); // nur Base64

        // In DB speichern (ohne Prefix)
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'slug'   => $slug,
                'link'   => $link,
                'qrcode' => $qrcodeRaw,
            ]);

        // In Response Prefix hinzufügen
        return response()->json([
            'link' => $link,
            'qrcode' => 'data:image/png;base64,' . $qrcodeRaw,
        ]);
    }


    public function PDFandPreview(int $planId, Request $request) : JsonResponse
    {
        $wifi = filter_var($request->query('wifi', false), FILTER_VALIDATE_BOOLEAN);

        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

/*

        // Passwort entschlüsseln
        $wifiPassword = '';
        if (!empty($event->wifi_password)) {
            try {
                $wifiPassword = Crypt::decryptString($event->wifi_password);
            } catch (\Exception $e) {
                // Falls es schon unverschlüsselt gespeichert war
                $wifiPassword = $event->wifi_password;
            }
        }

        // QR-Content abhängig vom Passwort
        if (!empty($wifiPassword)) {
            $wifiQrContent = "WIFI:T:WPA;S:{$event->wifi_ssid};P:{$wifiPassword};;";
        } else {
            $wifiQrContent = "WIFI:T:nopass;S:{$event->wifi_ssid};;";
        }

        $wifiQr = new \Endroid\QrCode\QrCode(
            $wifiQrContent,
            new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            \Endroid\QrCode\ErrorCorrectionLevel::High,
            300,
            10,
            \Endroid\QrCode\RoundBlockSizeMode::Margin,
            new \Endroid\QrCode\Color\Color(0, 0, 0),
            new \Endroid\QrCode\Color\Color(255, 255, 255)
        );

        $writer = new \Endroid\QrCode\Writer\PngWriter();

        // Logo optional hinzufügen
        $wifiLogo = null;
        $wifiLogoPath = public_path("flow/wifi.png");
        if (file_exists($wifiLogoPath)) {
            $wifiLogo = new \Endroid\QrCode\Logo\Logo($wifiLogoPath, 100);
        }

        // QR-Code schreiben mit Logo
        $wifiResult = $writer->write($wifiQr, $wifiLogo);
        $wifiQrcodeRaw = base64_encode($wifiResult->getString());

        // Speichern in DB
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'wifi_qrcode' => $wifiQrcodeRaw,
            ]);

            */

        // HTML fürs PDF
        $contentHtml = view('pdf.content.qr_codes', [
            'event'        => $event,
            'wifi'         => $wifi,
            'wifiPassword' => $wifiPassword,
        ])->render();

        $layout = app(\App\Services\PdfLayoutService::class);
        $html   = $layout->renderLayout($event, $contentHtml, 'Event Sheet');

        // PDF generieren
        $pdf = Pdf::loadHTML($html, 'UTF-8')->setPaper('a4', 'landscape');
        $pdfData = $pdf->output(); // Binary PDF

        // PDF -> PNG konvertieren
        $imagick = new \Imagick();
        $imagick->setResolution(100, 100);
        $imagick->readImageBlob($pdfData);
        $imagick->setIteratorIndex(0); // erste Seite
        $imagick->setImageFormat('png');
        $pngData = $imagick->getImageBlob();

        return response()->json([
            'pdf' => 'data:application/pdf;base64,' . base64_encode($pdfData),
            'preview' => 'data:image/png;base64,' . base64_encode($pngData),
        ]);
    }

    // Informationen fürs Volk ...


    public function scheduleInformation(int $eventId, Request $request): JsonResponse
    {
        // Level aus Tabelle publication holen
        $publication = DB::table('publication')
            ->where('event', $eventId)
            ->select('level')
            ->first();

        $level = $publication?->level ?? 1; // Fallback Level 1

        // Falls im Request level übergeben wird -> überschreibt DB-Wert
        $override = $request->input('level'); // liest Body ODER Query
        if ($override !== null) {
            $level = (int) $override;
        }

        // Basisdaten aus DrahtController holen
        $event = Event::findOrFail($eventId);
        $drahtCtrl = app(\App\Http\Controllers\Api\DrahtController::class);
        $drahtData = $drahtCtrl->show($event)->getData(true);


        // Ins Log schreiben
        // Log::info('DrahtController::show() data', $drahtData);


        // JSON bauen
        $data = [
            'event_id' => $eventId,
            'level'    => $level,
            'date'     => $event->date,
            'address'  => $drahtData['address'] ?? null,
            // hier direkt durchreichen:
            'contact'  => $drahtData['contact'] ?? [],
            'teams'    => [
                'explore' => [
                    'capacity'   => $drahtData['capacity_explore'] ?? 0,
                    'registered' => count($drahtData['teams_explore'] ?? []),
                    'list'       => $level >= 2 ? array_column($drahtData['teams_explore'], 'name') : [],
                ],
                'challenge' => [
                    'capacity'   => $drahtData['capacity_challenge'] ?? 0,
                    'registered' => count($drahtData['teams_challenge'] ?? []),
                    'list'       => $level >= 2 ? array_column($drahtData['teams_challenge'], 'name') : [],
                ],
            ],
        ];

        if ($level >= 3) {


            $importantTimesResponse = $this->importantTimes($eventId);
            $importantTimes = $importantTimesResponse->getData(true); // JSON -> Array

            // Ins Log schreiben
            Log::info('planController::importantTimes() data', $importantTimes);

            // Schedule ins Haupt-JSON einhängen
            $data['plan'] = $importantTimes;
        }

        return response()->json($data);
    }


    // Aktuellen Level holen
    public function getPublicationLevel(int $eventId): JsonResponse
    {
        $publication = DB::table('publication')
            ->where('event', $eventId)
            ->first();

        // Falls noch kein Eintrag vorhanden → neuen mit Level 1 anlegen
        if (!$publication) {
            DB::table('publication')->insert([
                'event'     => $eventId,
                'level'     => 1,
                'created_at'=> Carbon::now(),
                'updated_at'=> Carbon::now(),
            ]);

            $level = 1;
        } else {
            $level = $publication->level;
        }

        return response()->json([
            'event_id' => $eventId,
            'level'    => $level,
        ]);
    }

    // Level setzen/überschreiben
    public function setPublicationLevel(int $eventId, Request $request): JsonResponse
    {
        $level = (int) $request->input('level', 1);

        DB::table('publication')
            ->updateOrInsert(
                ['event' => $eventId],
                ['level' => $level, 'updated_at' => Carbon::now(),]
            );

        return response()->json([
            'success' => true,
            'event_id' => $eventId,
            'level'    => $level,
        ]);
    }

   // Wichtige Zeite für die Veröffentlichung 

    private function importantTimes(int $eventId): \Illuminate\Http\JsonResponse
    {

        Log::info('Fetching important times for event', ['event_id' => $eventId]);

        // Plan zum Event laden
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id', 'last_change')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan für dieses Event gefunden'], 404);
        }

        // Activities laden
        $activities = $this->fetcher->fetchActivities($plan->id);

        Log::info('Activities for importantTimes', ['count' => $activities->count()]);

        // Hilfsfunktion: Erste Startzeit für gegebene ATD-IDs finden
        $findStart = function($ids) use ($activities) {
            $act = $activities->first(fn($a) => in_array($a->activity_type_detail_id, (array) $ids));
            return $act ? $act->start_time : null;
        };

        // Hilfsfunktion: Ende der Aktivität (end_time) für gegebene ATD-IDs
        $findEnd = function($ids) use ($activities) {
            $act = $activities->first(fn($a) => in_array($a->activity_type_detail_id, (array) $ids));
            return $act ? $act->end_time : null;
        };

        $data = [
            'plan_id'      => $plan->id,
            'last_change' => $plan->last_change,
            'explore' => [
                'briefing' => [
                    'teams'  => $findStart(ID_ATD_E_COACH_BRIEFING),
                    'judges' => $findStart(ID_ATD_E_JUDGE_BRIEFING),
                ],
                'opening' => $findStart([ID_ATD_E_OPENING, ID_ATD_OPENING]), // spezifisch oder gemeinsam
                'end'     => $findEnd([ID_ATD_E_AWARDS, ID_ATD_AWARDS]),     // spezifisch oder gemeinsam
            ],
            'challenge' => [
                'briefing' => [
                    'teams'    => $findStart(ID_ATD_C_COACH_BRIEFING),
                    'judges'   => $findStart(ID_ATD_C_JUDGE_BRIEFING),
                    'referees' => $findStart(ID_ATD_R_REFEREE_BRIEFING),
                ],
                'opening' => $findStart([ID_ATD_C_OPENING, ID_ATD_OPENING]), // spezifisch oder gemeinsam
                'end'     => $findEnd([ID_ATD_C_AWARDS, ID_ATD_AWARDS]),     // spezifisch oder gemeinsam
            ],
        ];

        return response()->json($data);
    }

    

}