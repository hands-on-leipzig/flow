<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Carbon\Carbon;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;

use Illuminate\Support\Facades\Crypt;

use Barryvdh\DomPDF\Facade\Pdf;        // composer require barryvdh/laravel-dompdf


class PublishController extends Controller
{
    public function linkAndQRcode(int $planId): JsonResponse
    {
        // Plan → Event
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
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
        $link = "https://flow.hands-on-technology.org/" . $link;


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

        // HTML fürs PDF
        $html = $this->buildEventHtml($event, $wifi);
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


    private function buildEventHtml($event, bool $wifi = false): string
    {
        // Datum formatieren
        $formattedDate = '';
        if (!empty($event->date)) {
            try {
                $formattedDate = Carbon::parse($event->date)->format('d.m.Y');
            } catch (\Exception $e) {
                $formattedDate = $event->date;
            }
        }

        // Passwort entschlüsseln
        $wifiPassword = '';
        if (!empty($event->wifi_password)) {
            try {
                $wifiPassword = Crypt::decryptString($event->wifi_password);
            } catch (\Exception $e) {
                $wifiPassword = $event->wifi_password;
            }
        }

        // Explore-Logo laden
        $exploreLogoPath = public_path('flow/fll_explore_hs.png');
        $exploreLogoSrc = (file_exists($exploreLogoPath) && !empty($event->event_explore))
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($exploreLogoPath))
            : '';

        // Challenge-Logo laden
        $challengeLogoPath = public_path('flow/fll_challenge_hs.png');
        $challengeLogoSrc = (file_exists($challengeLogoPath) && !empty($event->event_challenge))
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($challengeLogoPath))
            : '';

        // Linke Zelle mit dynamischen Logos
        $leftLogosHtml = '';
        if ($exploreLogoSrc) {
            $leftLogosHtml .= '<img src="'.$exploreLogoSrc.'" style="height:80px; width:auto; margin-right:10px;" />';
        }
        if ($challengeLogoSrc) {
            $leftLogosHtml .= '<img src="'.$challengeLogoSrc.'" style="height:80px; width:auto;" />';
        }



        // Logos (aus /public/flow/...) als Base64 einbetten – dompdf-sicher
        $rightLogoPath = public_path('flow/hot.png');

        $rightLogoSrc = file_exists($rightLogoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($rightLogoPath))
            : '';




        $html = '
        <div style="width: 100%; font-family: sans-serif; text-align: center; padding: 40px;">
            
            <table style="width:100%; table-layout:fixed; border-collapse:collapse; margin-bottom:30px;">
            <tr>
                <td style="width:33%; text-align:left; vertical-align:top;">
                '.$leftLogosHtml.'
                </td>
                <td style="width:34%; text-align:center; vertical-align:top;">
                    <div style="font-size:20px; margin-bottom:6px; font-weight:normal;">FIRST LEGO League Wettbewerb</div>
                    <div style="font-size:28px; font-weight:bold;">' . e($event->name) . ' ' . e($formattedDate) . '</div>
                </td>
                <td style="width:33%; text-align:right; vertical-align:top;">
                ' . ($rightLogoSrc ? '<img src="'.$rightLogoSrc.'" style="height:80px; width:auto;" />' : '') . '
                </td>
            </tr>
            </table>';

        // Plan-QR ist immer dabei
        $qr_plan = '
            <div style="margin-top: 10px; font-size: 20px; color: #333;">Online Zeitplan</div>
            <img src="data:image/png;base64,' . $event->qrcode . '" style="width:200px; height:200px;" />
            <div style="margin-top: 10px; font-size: 16px; color: #333;">' . e($event->link) . '</div>';

        if ($wifi && !empty($event->wifi_ssid) && !empty($wifiPassword)) {
            // WLAN-QR nur wenn gewünscht und Daten vorhanden
            $wifiQrContent = "WIFI:T:WPA;S:{$event->wifi_ssid};P:{$wifiPassword};;";
            $wifiQr = new \Endroid\QrCode\QrCode($wifiQrContent);
            $writer = new \Endroid\QrCode\Writer\PngWriter();
            $wifiResult = $writer->write($wifiQr);
            $wifiBase64 = base64_encode($wifiResult->getString());

            $html .= '
                <table style="width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 40px;">
                    <tr>
                        <td style="width: 50%; text-align: center; vertical-align: top; padding: 10px;">
                            ' . $qr_plan . '
                        </td>
                        <td style="width: 50%; text-align: center; vertical-align: top; padding: 10px;">
                            <div style="margin-top: 10px; font-size: 20px; color: #333;">
                                Kostenloses WLAN
                            </div>
                            <img src="data:image/png;base64,' . $wifiBase64 . '" style="width:200px; height:200px;" />
                            <div style="margin-top: 10px; font-size: 14px; color: #333;">
                                SSID: ' . e($event->wifi_ssid) . '<br/>
                                Passwort: ' . e($wifiPassword) . '
                            </div>
                        </td>
                    </tr>
                </table>';
        } else {
            // Nur Plan-QR
            $html .= '
                <div style="text-align: center; margin-bottom: 40px;">' 
                    . $qr_plan .
                '</div>';
        }

        // Logos laden
        $logos = DB::table('logo')
            ->join('event_logo', 'event_logo.logo', '=', 'logo.id')
            ->where('event_logo.event', $event->id)
            ->select('logo.*')
            ->get();

        if ($logos->count() > 0) {
            $html .= '
                <table style="width: 100%; border-collapse: collapse; margin-top: 40px;">
                    <tr>';

            foreach ($logos as $logo) {
                // Pfad in storage -> public URL
                $logoPath = storage_path('app/public/' . $logo->path);

                // Log::info('Logo path: ' . $logoPath);

                if (file_exists($logoPath)) {
                    $base64 = base64_encode(file_get_contents($logoPath));
                    $src = 'data:image/png;base64,' . $base64;

                    $html .= '
                        <td style="text-align: center; vertical-align: middle; padding: 10px;">
                            <img src="' . $src . '" style="height:80px; max-width:100%; object-fit: contain;" />
                        </td>';
                }
            }

            $html .= '
                    </tr>
                </table>';
        }

        $html .= '</div>'; // Wrapper schließen         

        return $html;
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
        Log::info('DrahtController::show() data', $drahtData);




    // JSON bauen
    $data = [
        'event_id' => $eventId,
        'level'    => $level,
        'date'     => $drahtData['information']['date'] ?? null,
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
            $data['schedule'] = [
                'explore' => [
                    'briefings' => '09:00 Uhr',
                    'opening'   => '10:00 Uhr',
                    'end'       => '15:00 Uhr',
                ],
                'challenge' => [
                    'briefings' => '08:30 Uhr',
                    'opening'   => '09:30 Uhr',
                    'end'       => '18:00 Uhr',
                ],
            ];
        }

        return response()->json($data);
    }


}