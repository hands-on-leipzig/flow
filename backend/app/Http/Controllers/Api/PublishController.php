<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MActivityTypeDetail;
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
        $link = config('app.frontend_url', 'http://localhost:5173') . "/" . $link;


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

        // Update link in DRAHT for both explore and challenge events if they exist
        try {
            $drahtController = app(\App\Http\Controllers\Api\DrahtController::class);
            
            // Update link for challenge event if it exists
            if (!empty($event->event_challenge)) {
                $drahtController->updateEventLink($event->event_challenge, $link);
            }
            
            // Update link for explore event if it exists
            if (!empty($event->event_explore)) {
                $drahtController->updateEventLink($event->event_explore, $link);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the link generation
            Log::error("Failed to update link in DRAHT for event {$event->id}", [
                'error' => $e->getMessage()
            ]);
        }

        // In Response Prefix hinzufügen
        return response()->json([
            'link' => $link,
            'qrcode' => 'data:image/png;base64,' . $qrcodeRaw,
        ]);
    }

    /**
     * Regenerate link and QR code for an event (admin only)
     */
    public function regenerateLinkAndQRcode(int $eventId): JsonResponse
    {
        // Event direkt laden
        $event = DB::table('event')
            ->where('id', $eventId)
            ->first();

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        // Clear existing link and QR code to force regeneration
        DB::table('event')
            ->where('id', $eventId)
            ->update([
                'slug' => null,
                'link' => null,
                'qrcode' => null,
            ]);

        // Now call the existing method to regenerate
        return $this->linkAndQRcode($eventId);
    }

    /**
     * Regenerate links and QR codes for all events in a season (admin only)
     */
    public function regenerateLinksForSeason(int $seasonId): JsonResponse
    {
        try {
            // Get all events for this season
            $events = DB::table('event')
                ->where('season', $seasonId)
                ->get();

            if ($events->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No events found for this season',
                    'regenerated' => 0,
                    'failed' => 0
                ], 404);
            }

            $regenerated = 0;
            $failed = 0;
            $errors = [];

            Log::info("Regenerating links for season {$seasonId}", [
                'event_count' => $events->count()
            ]);

            foreach ($events as $event) {
                try {
                    // Clear existing link and QR code to force regeneration
                    DB::table('event')
                        ->where('id', $event->id)
                        ->update([
                            'slug' => null,
                            'link' => null,
                            'qrcode' => null,
                        ]);

                    // Regenerate link and QR code
                    $this->linkAndQRcode($event->id);
                    $regenerated++;
                    
                    Log::info("Regenerated link for event {$event->id} ({$event->name})");
                } catch (\Exception $e) {
                    $failed++;
                    $errorMsg = "Failed to regenerate link for event {$event->id} ({$event->name}): " . $e->getMessage();
                    $errors[] = $errorMsg;
                    Log::error($errorMsg, [
                        'event_id' => $event->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Regenerated links for {$regenerated} events" . ($failed > 0 ? ", {$failed} failed" : ''),
                'regenerated' => $regenerated,
                'failed' => $failed,
                'total' => $events->count(),
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error("Error regenerating links for season {$seasonId}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
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


        // Get color information from m_first_program table
        $exploreColor = DB::table('m_first_program')
            ->where('name', 'EXPLORE')
            ->value('color_hex') ?? '00A651'; // Default green if not found
        
        $challengeColor = DB::table('m_first_program')
            ->where('name', 'CHALLENGE')
            ->value('color_hex') ?? 'ED1C24'; // Default red if not found

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
                    'color_hex' => $exploreColor,
                    'list'       => $level >= 1 ? array_map(function($team) {
                        return [
                            'team_number_hot' => $team['team_number_hot'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? ''
                        ];
                    }, $drahtData['teams_explore'] ?? []) : [],
                ],
                'challenge' => [
                    'capacity'   => $drahtData['capacity_challenge'] ?? 0,
                    'registered' => count($drahtData['teams_challenge'] ?? []),
                    'color_hex' => $challengeColor,
                    'list'       => $level >= 1 ? array_map(function($team) {
                        return [
                            'team_number_hot' => $team['team_number_hot'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? ''
                        ];
                    }, $drahtData['teams_challenge'] ?? []) : [],
                ],
            ],
        ];

        if ($level >= 3) {


            $importantTimesResponse = $this->importantTimes($eventId);
            $importantTimes = $importantTimesResponse->getData(true); // JSON -> Array

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

        // Activity Type Detail IDs by code (cached lookup)
        $atdIds = MActivityTypeDetail::whereIn('code', [
            'e_briefing_coach',
            'e_briefing_judge',
            'e_opening',
            'e_awards',
            'g_opening',
            'g_awards',
            'c_briefing',
            'j_briefing',
            'r_briefing',
            'c_opening',
            'c_awards',
        ])->pluck('id', 'code');

        // Hilfsfunktion: Erste Startzeit für gegebene codes finden
        $findStart = function($codes) use ($activities, $atdIds) {
            $ids = collect((array) $codes)->map(fn($code) => $atdIds[$code] ?? null)->filter();
            $act = $activities->first(fn($a) => $ids->contains($a->activity_type_detail_id));
            return $act ? $act->start_time : null;
        };

        // Hilfsfunktion: Ende der Aktivität (end_time) für gegebene codes
        $findEnd = function($codes) use ($activities, $atdIds) {
            $ids = collect((array) $codes)->map(fn($code) => $atdIds[$code] ?? null)->filter();
            $act = $activities->first(fn($a) => $ids->contains($a->activity_type_detail_id));
            return $act ? $act->end_time : null;
        };

        $data = [
            'plan_id'      => $plan->id,
            'last_change' => $plan->last_change,
            'explore' => [
                'briefing' => [
                    'teams'  => $findStart('e_briefing_coach'),
                    'judges' => $findStart('e_briefing_judge'),
                ],
                'opening' => $findStart(['e_opening', 'g_opening']), // spezifisch oder gemeinsam
                'end'     => $findEnd(['e_awards', 'g_awards']),     // spezifisch oder gemeinsam
            ],
            'challenge' => [
                'briefing' => [
                    'teams'    => $findStart('c_briefing'),
                    'judges'   => $findStart('j_briefing'),
                    'referees' => $findStart('r_briefing'),
                ],
                'opening' => $findStart(['c_opening', 'g_opening']), // spezifisch oder gemeinsam
                'end'     => $findEnd(['c_awards', 'g_awards']),     // spezifisch oder gemeinsam
            ],
        ];

        return response()->json($data);
    }

     /**
     * Gemeinsamer Builder: Erzeugt HTML aus Event + Typ
     */
    private function buildEventSheetHtml(string $type, int $eventId): string
    {
        $event = \App\Models\Event::findOrFail($eventId);

        // WLAN-Passwort entschlüsseln
        $wifiPassword = '';
        if (!empty($event->wifi_password)) {
            try {
                $wifiPassword = Crypt::decryptString($event->wifi_password);
            } catch (\Exception $e) {
                $wifiPassword = $event->wifi_password;
            }
        }

        // Inhalt + Layout rendern
        $contentHtml = view('pdf.content.qr_codes', [
            'event'        => $event,
            'wifi'         => $type === 'plan_wifi',
            'wifiPassword' => $wifiPassword,
        ])->render();

        $layout = app(\App\Services\PdfLayoutService::class);
        return $layout->renderLayout($event, $contentHtml, 'Event Sheet');
    }

    /**
     * Gemeinsamer Renderer: Erzeugt PDF und (optional) PNG
     */
    private function buildEventSheetPdf(string $type, int $eventId, bool $asPng = false)
    {

        // log::alert("buildEventSheetPdf: type=$type, eventId=$eventId, asPng=" . ($asPng ? 'true' : 'false'));

        $html = $this->buildEventSheetHtml($type, $eventId);

        // PDF generieren (DomPDF)
        $pdf = Pdf::loadHTML($html, 'UTF-8')->setPaper('a4', 'landscape');
        $pdfData = $pdf->output();

        if (!$asPng) {

            // log::alert("PDF generated, size: " . strlen($pdfData) . " bytes");

            return $pdfData;
        }

        // log::alert("Converting PDF to PNG...");

        // PDF -> PNG konvertieren (erste Seite)
        $imagick = new \Imagick();
        $imagick->setResolution(120, 120);
        $imagick->readImageBlob($pdfData);
        $imagick->setIteratorIndex(0);
        $imagick->setImageFormat('png');
        $imagick->setImageCompressionQuality(90);
        $pngData = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        // log::alert("Conversion done, PNG size: " . strlen($pngData) . " bytes");

        return $pngData;
    }

    /**
     * PDF Download (mit Header & Dateiname)
     */
    public function download(string $type, int $eventId)
    {
        $pdfData = $this->buildEventSheetPdf($type, $eventId, false);

        $formattedDate = now()->format('d.m.y');
        $name = $type === 'plan_wifi' ? 'Plan_mit_WLAN' : 'Plan';
        $filename = "FLOW_{$name}_({$formattedDate}).pdf";

        return response($pdfData, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
            ->header('X-Filename', $filename)
            ->header('Access-Control-Expose-Headers', 'X-Filename');
    }

    /**
     * PNG Preview (aus PDF)
     */
    public function preview(string $type, int $eventId)
    {
        $pngData = $this->buildEventSheetPdf($type, $eventId, true);

        return response('data:image/png;base64,' . base64_encode($pngData))
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
