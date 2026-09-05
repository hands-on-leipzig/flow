<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\FlowFilename;
use App\Models\Event;
use App\Models\OneLinkAccess;
use App\Services\EventSlugService;
use App\Services\ImportantTimesService;
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


use Barryvdh\DomPDF\Facade\Pdf;

// composer require barryvdh/laravel-dompdf


class PublishController extends Controller
{
    public function __construct(private readonly EventSlugService $slugs) {}

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
            // For existing QR codes, regenerate with ?source=qr if not already present
            // But return the clean display link
            return response()->json([
                'link' => $event->link,  // Clean display link
                'slug' => $event->slug,
                'qrcode' => 'data:image/png;base64,' . $event->qrcode,
            ]);
        }

        if (empty($event->name)) {
            return response()->json(['error' => 'Event name is required'], 400);
        }

        $eventModel = Event::find($event->id);
        if (! $eventModel) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        // Slug and public URL come from the central registry that DRAHT and JOIN read
        // through the external API, so the naming rules live in one place.
        try {
            $slug = $this->slugs->regenerate($eventModel);
        } catch (\InvalidArgumentException $e) {
            Log::error("Failed to assign slug for event {$event->id}", ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Display link (stored in DB, shown to users) - clean without query params
        $displayLink = $this->slugs->url($eventModel);
        // QR code link (includes source parameter for tracking)
        $qrCodeLink = $displayLink . "?source=qr";

        // QR-Code mit Endroid erzeugen (use QR code link with source parameter)
        $qrCode = new QrCode(
            $qrCodeLink,
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

        // In DB speichern (ohne Prefix) - store clean display link without ?source=qr
        // Slug is already persisted by the registry.
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'link' => $displayLink,  // Clean link without ?source=qr
                'qrcode' => $qrcodeRaw,
            ]);

        // Update link in DRAHT for both explore and challenge events if they exist
        foreach ($eventModel->programs as $program) {
            if (! empty($program->draht_id)) {
                $this->pushLinkToDraht($eventModel, (int) $program->draht_id);
            }
        }

        app(\App\Services\CalendarFeedService::class)->rebuildSafely((int) $event->id);

        return response()->json([
            'link' => $displayLink,
            'slug' => $slug,
            'qrcode' => 'data:image/png;base64,' . $qrcodeRaw,
        ]);
    }

    /**
     * Set the slug of an event by hand. It is marked as manual so later regeneration
     * keeps it, and link, QR code and the copies in DRAHT are rebuilt to match.
     */
    public function setSlug(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:255',
        ]);

        $event = Event::find($eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        try {
            $this->slugs->assign($event, $validated['slug'], true);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Link and QR code carry the slug, so both have to be built again.
        DB::table('event')
            ->where('id', $eventId)
            ->update([
                'link' => null,
                'qrcode' => null,
            ]);

        return $this->linkAndQRcode($eventId);
    }

    /**
     * Send the public link of an event to one DRAHT event. Called per program, because
     * Explore and Challenge are separate events in DRAHT but share the FLOW slug.
     */
    public function pushLinkToDraht(Event $event, int $drahtId): void
    {
        if (! app()->environment('production')) {
            Log::info("Skipping DRAHT link update for event {$event->id} (environment: " . app()->environment() . ")");

            return;
        }

        $link = $this->slugs->url($event);
        if ($link === null) {
            Log::warning("No public link to push to DRAHT for event {$event->id}");

            return;
        }

        try {
            app(\App\Http\Controllers\Api\DrahtController::class)->updateEventLink($drahtId, $link);
        } catch (\Exception $e) {
            // Log error but don't fail the link generation
            Log::error("Failed to update link in DRAHT for event {$event->id}", [
                'draht_id' => $drahtId,
                'error' => $e->getMessage(),
            ]);
        }
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

        // Clear link and QR code to force regeneration. The slug stays so the registry
        // can move it to the history and keep the old URL redirectable.
        DB::table('event')
            ->where('id', $eventId)
            ->update([
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

            $eventCount = $events->count();

            // Increase execution time limit for batch operation
            // Allow ~10 seconds per event, minimum 60 seconds, maximum 600 seconds (10 minutes)
            $estimatedTime = max(60, min(600, $eventCount * 10));
            set_time_limit($estimatedTime);
            ini_set('max_execution_time', $estimatedTime);

            $regenerated = 0;
            $failed = 0;
            $errors = [];

            Log::info("Regenerating links for season {$seasonId}", [
                'event_count' => $eventCount,
                'time_limit' => $estimatedTime
            ]);

            foreach ($events as $index => $event) {
                try {
                    // Clear link and QR code to force regeneration; the registry keeps
                    // the slug and records a replacement only when it really changes.
                    DB::table('event')
                        ->where('id', $event->id)
                        ->update([
                            'link' => null,
                            'qrcode' => null,
                        ]);

                    // Regenerate link and QR code
                    $this->linkAndQRcode($event->id);
                    $regenerated++;

                    // Log progress every 10 events or on last event
                    if (($index + 1) % 10 === 0 || ($index + 1) === $eventCount) {
                        Log::info("Progress: {$regenerated}/{$eventCount} events regenerated for season {$seasonId}");
                    } else {
                        Log::info("Regenerated link for event {$event->id} ({$event->name})");
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $errorMsg = "Failed to regenerate link for event {$event->id} ({$event->name}): " . $e->getMessage();
                    $errors[] = $errorMsg;
                    Log::error($errorMsg, [
                        'event_id' => $event->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Regenerated links for {$regenerated} events" . ($failed > 0 ? ", {$failed} failed" : ''),
                'regenerated' => $regenerated,
                'failed' => $failed,
                'total' => $eventCount,
                'errors' => $errors
            ]);

        } catch (\Throwable $e) {
            // Catch both Exception and Error (like FatalError) for better error handling
            Log::error("Error regenerating links for season {$seasonId}: " . $e->getMessage(), [
                'error_type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'Maximum execution time')) {
                $errorMessage = 'Operation timed out. Please try with fewer events or increase PHP max_execution_time.';
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage
            ], 500);
        }
    }


    // Informationen fürs Volk ...


    public function scheduleInformation(int $eventId, Request $request): JsonResponse
    {
        // Level aus Tabelle publication holen (latest entry)
        $publication = DB::table('publication')
            ->where('event', $eventId)
            ->orderBy('last_change', 'desc')
            ->orderBy('id', 'desc')
            ->select('level')
            ->first();

        $level = $publication?->level ?? 1; // Fallback Level 1

        // Falls im Request level übergeben wird -> überschreibt DB-Wert
        $override = $request->input('level'); // liest Body ODER Query
        if ($override !== null) {
            $level = (int)$override;
        }

        // Basisdaten aus DrahtController holen
        $event = Event::findOrFail($eventId);
        $drahtCtrl = app(\App\Http\Controllers\Api\DrahtController::class);
        $drahtData = $drahtCtrl->show($event)->getData(true);

        $plan = null;
        if ($level >= 3) {
            $plan = $this->importantTimesPayload($eventId);
        }

        $payload = \App\Support\PublicSchedulePayload::from($event, $drahtData, $level, $plan);

        if ($level < 4 && (bool) $event->public_helper_search) {
            $payload['helper_search'] = \App\Support\PublicHelperSearchPayload::forEvent($event);
        }

        if ($level < 4 && (bool) $event->public_volunteer_data_entry) {
            $payload['volunteer_data_entry'] = ['enabled' => true];
        }

        if ($level < 4 && (bool) $event->public_team_data_entry) {
            $payload['team_data_entry'] = ['enabled' => true];
        }

        return response()->json($payload);
    }

    public function getPublicHelperSearch(int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        return response()->json([
            'event_id' => $eventId,
            'public_helper_search' => (bool) $event->public_helper_search,
        ]);
    }

    public function setPublicHelperSearch(int $eventId, Request $request): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        $enabled = $request->boolean('public_helper_search');
        $event->public_helper_search = $enabled;
        $event->save();

        return response()->json([
            'success' => true,
            'event_id' => $eventId,
            'public_helper_search' => (bool) $event->public_helper_search,
        ]);
    }

    public function getPublicVolunteerDataEntry(int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        return response()->json([
            'event_id' => $eventId,
            'public_volunteer_data_entry' => (bool) $event->public_volunteer_data_entry,
        ]);
    }

    public function setPublicVolunteerDataEntry(int $eventId, Request $request): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        $enabled = $request->boolean('public_volunteer_data_entry');
        $event->public_volunteer_data_entry = $enabled;
        $event->save();

        return response()->json([
            'success' => true,
            'event_id' => $eventId,
            'public_volunteer_data_entry' => (bool) $event->public_volunteer_data_entry,
        ]);
    }

    public function getPublicTeamDataEntry(int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        return response()->json([
            'event_id' => $eventId,
            'public_team_data_entry' => (bool) $event->public_team_data_entry,
        ]);
    }

    public function setPublicTeamDataEntry(int $eventId, Request $request): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        $enabled = $request->boolean('public_team_data_entry');
        $event->public_team_data_entry = $enabled;
        $event->save();

        return response()->json([
            'success' => true,
            'event_id' => $eventId,
            'public_team_data_entry' => (bool) $event->public_team_data_entry,
        ]);
    }

    /**
     * importantTimes JSON as an array (same body as the private HTTP helper).
     *
     * @return array<string, mixed>
     */
    public function importantTimesPayload(int $eventId): array
    {
        return $this->importantTimes($eventId)->getData(true);
    }


    // Aktuellen Level holen
    public function getPublicationLevel(int $eventId): JsonResponse
    {
        // Get latest entry (by last_change DESC, then id DESC)
        $publication = DB::table('publication')
            ->where('event', $eventId)
            ->orderBy('last_change', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        // Falls noch kein Eintrag vorhanden → neuen mit Level 1 anlegen
        if (!$publication) {
            DB::table('publication')->insert([
                'event' => $eventId,
                'level' => 1,
                'last_change' => Carbon::now(),
            ]);

            $level = 1;
        } else {
            $level = $publication->level;
        }

        return response()->json([
            'event_id' => $eventId,
            'level' => $level,
        ]);
    }

    // Level setzen/überschreiben
    public function setPublicationLevel(int $eventId, Request $request): JsonResponse
    {
        $level = (int)$request->input('level', 1);

        // Get current latest level
        $latest = DB::table('publication')
            ->where('event', $eventId)
            ->orderBy('last_change', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        // Only insert if level actually changed (avoid duplicates)
        if (!$latest || $latest->level !== $level) {
            DB::table('publication')->insert([
                'event' => $eventId,
                'level' => $level,
                'last_change' => Carbon::now(),
            ]);
            app(\App\Services\CalendarFeedService::class)->rebuildSafely($eventId);
        }

        return response()->json([
            'success' => true,
            'event_id' => $eventId,
            'level' => $level,
        ]);
    }

    // Wichtige Zeite für die Veröffentlichung

    private function importantTimes(int $eventId): \Illuminate\Http\JsonResponse
    {
        $data = app(ImportantTimesService::class)->forEvent($eventId);

        if (($data['_status'] ?? null) === 404) {
            return response()->json(['error' => $data['error']], 404);
        }

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

        // Get footer logos for QR PDF (logos will be rendered in content area)
        $pdfLayoutService = app(\App\Services\PdfLayoutService::class);
        $footerLogos = $pdfLayoutService->buildFooterLogos($event->id);

        // Inhalt + Layout rendern
        $contentHtml = view('pdf.content.qr_codes', [
            'event' => $event,
            'wifi' => $type === 'plan_wifi',
            'wifiPassword' => $wifiPassword,
            'footerLogos' => $footerLogos, // Pass logos to content template
        ])->render();

        $layout = app(\App\Services\PdfLayoutService::class);
        return $layout->renderLayout($event, $contentHtml, 'Event Sheet', true); // true = isQrCodePdf
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

        $event = Event::find($eventId);
        $name = $type === 'plan_wifi' ? 'Plan_mit_WLAN' : 'Plan';
        $filename = FlowFilename::make($name, 'pdf', $event?->date);

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

    /**
     * Log one-link access (public event page access)
     * No authentication required - public endpoint
     */
    public function logOneLinkAccess(Request $request): JsonResponse
    {
        try {
            // Validate event_id exists
            $eventId = $request->input('event_id');
            if (!$eventId) {
                return response()->json(['error' => 'event_id is required'], 400);
            }

            $event = Event::find($eventId);
            if (!$event) {
                return response()->json(['error' => 'Event not found'], 400);
            }

            // Extract server-side data from request
            $userAgent = $request->userAgent();
            $referrer = $request->header('referer');
            $ip = $request->ip();
            $ipHash = hash('sha256', $ip . config('app.key'));
            $acceptLanguage = $request->header('accept-language');

            // Extract client-side data from request body
            $screenWidth = $request->input('screen_width');
            $screenHeight = $request->input('screen_height');
            $viewportWidth = $request->input('viewport_width');
            $viewportHeight = $request->input('viewport_height');
            $devicePixelRatio = $request->input('device_pixel_ratio');
            $touchSupport = $request->input('touch_support');
            $connectionType = $request->input('connection_type');

            // Determine source
            $source = $request->input('source', 'unknown');
            if ($source === 'qr') {
                $source = 'qr';
            } elseif ($referrer) {
                $source = 'referrer';
            } else {
                $source = 'direct';
            }

            // Insert record into database
            OneLinkAccess::create([
                'event' => $eventId,
                'access_date' => Carbon::now()->toDateString(),
                'access_time' => Carbon::now(),
                'user_agent' => $userAgent,
                'referrer' => $referrer,
                'ip_hash' => $ipHash,
                'accept_language' => $acceptLanguage ? substr($acceptLanguage, 0, 50) : null,
                'screen_width' => $screenWidth,
                'screen_height' => $screenHeight,
                'viewport_width' => $viewportWidth,
                'viewport_height' => $viewportHeight,
                'device_pixel_ratio' => $devicePixelRatio,
                'touch_support' => $touchSupport,
                'connection_type' => $connectionType ? substr($connectionType, 0, 20) : null,
                'source' => $source,
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            // Log error but don't fail - silent failure for user experience
            Log::error('Failed to log one-link access', [
                'error' => $e->getMessage(),
                'event_id' => $request->input('event_id'),
            ]);
            return response()->json(['error' => 'Failed to log access'], 500);
        }
    }
}
