<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\Slide;
use App\Models\TableEvent;
use App\Models\User;
use App\Services\SeasonService;
use App\Services\EventAttentionService;
use App\Support\ProgramCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;


class EventController extends Controller
{
    // Test deployment: verifying new deployment workflow with real content change

    public function index()
    {
        $events = Event::where('season', SeasonService::currentSeasonId());
        $response = [];
        foreach ($events->get() as $event) {
            $response[$event->slug] = sprintf('%s (%s)', $event->name, $event->date);
        }
        return response()->json($response);
    }

    public function getEvent($id)
    {
        $event = Event::with(['seasonRel', 'levelRel', 'tableNames'])->findOrFail($id);

        // Decrypt password before refresh (so it's preserved)
        $decryptedPassword = isset($event->wifi_password) ? Crypt::decryptString($event->wifi_password) : "";

        // Lazy initialization: calculate attention status if not yet calculated
        $attentionService = app(EventAttentionService::class);
        $attentionService->ensureAttentionStatusCalculated($event->id);

        // Reload event to get updated needs_attention values
        $event->refresh();

        // Restore decrypted password after refresh
        $event->wifi_password = $decryptedPassword;

        // Ensure needs_attention fields are included in response
        return response()->json([
            ...$event->toArray(),
            'needs_attention' => $event->needs_attention ?? false,
            'needs_attention_checked_at' => $event->needs_attention_checked_at,
        ]);
    }

    // Convert an event to an array containing only public information (e.g. no wifi_password)
    function eventPublicInformationArray($event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'date' => $event->date,
            'days' => $event->days,
            'programs' => $event->programs,
            'link' => $event->link,
            'qrcode' => $event->qrcode ? 'data:image/png;base64,' . $event->qrcode : null,
            'season' => $event->season,
            'level' => $event->level,
            'regional_partner' => $event->regional_partner,
            'seasonRel' => $event->seasonRel,
            'levelRel' => $event->levelRel,
            'regionalPartnerRel' => $event->regionalPartner,
        ];
    }

    public function getEventBySlug($slug)
    {
        try {
            $event = Event::where('slug', $slug)
                ->where('season', SeasonService::currentSeasonId())
                ->first();

            if (!$event) {
                return response()->json(['error' => 'Event not found'], 404);
            }

            // Load relationships separately to avoid potential issues
            $event->load(['seasonRel', 'levelRel', 'regionalPartner']);

            // Return only public information (no sensitive data like wifi_password)
            return response()->json($this->eventPublicInformationArray($event));
        } catch (\Exception $e) {
            Log::error('Error in getEventBySlug: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function getPublicEventById($eventId)
    {
        try {
            $event = Event::where('id', $eventId)
                ->where('season', SeasonService::currentSeasonId())
                ->first();

            if (!$event) {
                return response()->json(['error' => 'Event not found'], 404);
            }

            // Load relationships separately to avoid potential issues
            $event->load(['seasonRel', 'levelRel', 'regionalPartner']);

            // Return only public information (no sensitive data like wifi_password)
            return response()->json($this->eventPublicInformationArray($event));
        } catch (\Exception $e) {
            Log::error('Error in getPublicEventById: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function getSelectableEvents(Request $request)
    {
        $user = $request->user() ?? Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $seasonId = $request->query('season');
        $season = $seasonId
            ? MSeason::find($seasonId)
            : MSeason::latest('year')->first();

        if (!$season) {
            return response()->json([]);
        }

        // Prefer roles from the current JWT attribute (same source as middleware).
        $roles = \App\Support\FlowAccess::rolesFromJwt($request->attributes->get('jwt'));
        $isAdmin = \App\Support\FlowAccess::isAdmin($roles) || $user->isFlowAdmin();

        // One events query (+ light joins) instead of whereHas-per-partner (was 20s+).
        $eventsQuery = Event::query()
            ->where('season', $season->id)
            ->with(['seasonRel', 'levelRel', 'regionalPartner'])
            ->orderBy('date')
            ->orderBy('name');

        if (!$isAdmin) {
            $rpIds = $user->regionalPartners()
                ->pluck('regional_partner.id')
                ->filter()
                ->values()
                ->all();

            if ($rpIds === []) {
                return response()->json([]);
            }

            $eventsQuery->whereIn('regional_partner', $rpIds);
        }

        $events = $eventsQuery->get();

        $grouped = $events
            ->groupBy(fn (Event $event) => $event->regional_partner)
            ->map(function ($rpEvents) {
                /** @var Event $first */
                $first = $rpEvents->first();
                $rp = $first?->regionalPartner;

                return [
                    'regional_partner' => [
                        'id' => $rp?->id ?? $first?->regional_partner,
                        'name' => $rp?->name ?? '—',
                        'region' => $rp?->region,
                    ],
                    'events' => $rpEvents->map(function (Event $event) {
                        return [
                            'id' => $event->id,
                            'name' => $event->name,
                            'date' => $event->date,
                            'slug' => $event->slug,
                            'season' => [
                                'id' => $event->seasonRel?->id,
                                'name' => $event->seasonRel?->name,
                                'year' => $event->seasonRel?->year,
                            ],
                            'level' => [
                                'id' => $event->levelRel?->id,
                                'name' => $event->levelRel?->name,
                            ],
                            'programs' => $event->programs,
                        ];
                    })->values(),
                ];
            })
            ->sortBy(fn (array $group) => mb_strtolower($group['regional_partner']['name'] ?? ''))
            ->values();

        return response()->json($grouped);
    }

    public function getCreateEventData()
    {
        $regionalPartners = RegionalPartner::select('id', 'name', 'region')
            ->orderBy('name')
            ->get()
            ->map(function ($partner) {
                return [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'region' => $partner->region,
                    'display_name' => "{$partner->name} ({$partner->region})"
                ];
            });

        $levels = DB::table('m_level')
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'regional_partners' => $regionalPartners,
            'levels' => $levels,
            'programs' => ProgramCatalog::attachable()->map(fn ($program) => [
                'id' => $program->id,
                'name' => $program->name,
                'display_name' => $program->display_name,
                'letter' => $program->letter,
                'sequence' => $program->sequence,
                'color_hex' => $program->color_hex,
                'logo_stem' => $program->logo_stem,
                'logo_white' => $program->logo_white,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'regional_partner' => 'required|integer|exists:regional_partner,id',
            'level' => 'required|integer|exists:m_level,id',
            'date' => 'required|date',
            'days' => 'integer|min:1|max:10',
            'programs' => 'nullable|array',
            'programs.*.first_program' => 'required_with:programs|integer|exists:m_first_program,id',
            'programs.*.draht_id' => 'nullable|integer',
            'programs.*.contao_id' => 'nullable|integer',
        ]);

        // Get the latest season
        $season = MSeason::latest('year')->first();
        if (!$season) {
            return response()->json(['error' => 'No season found'], 400);
        }

        $event = Event::create([
            'name' => $validated['name'],
            'regional_partner' => $validated['regional_partner'],
            'season' => $season->id,
            'level' => $validated['level'],
            'date' => $validated['date'],
            'days' => $validated['days'] ?? 1,
        ]);

        if (! empty($validated['programs'])) {
            ProgramCatalog::sync($event, $validated['programs']);
        }

        // Automatically generate link and QR code for new events
        try {
            $publishController = app(\App\Http\Controllers\Api\PublishController::class);
            $publishController->linkAndQRcode($event->id);
            Log::info("Automatically generated link and QR code for new event {$event->id}");
        } catch (\Exception $e) {
            Log::error("Failed to auto-generate link and QR code for event {$event->id}", [
                'error' => $e->getMessage()
            ]);
            // Don't fail the entire process if link generation fails
        }

        return response()->json([
            'message' => 'Event created successfully',
            'event' => $event->load(['seasonRel', 'levelRel', 'programs'])
        ], 201);
    }

    public function update(Request $request, int $eventId)
    {
        $updatableFields = ['wifi_ssid', 'wifi_password', 'wifi_instruction'];
        $data = $request->only($updatableFields);

        // Passwort verschlüsseln
        if (!empty($data['wifi_password'])) {
            $data['wifi_password'] = Crypt::encryptString($data['wifi_password']);
        }

        // Update nur für dieses Event
        DB::table('event')->where('id', $eventId)->update($data);

        // QR-Code nur erzeugen, wenn SSID oder Passwort geändert wurden
        if (!empty($data['wifi_ssid']) || !empty($data['wifi_password'])) {
            $event = DB::table('event')
                ->where('id', $eventId)
                ->select('wifi_ssid', 'wifi_password')
                ->first();

            if ($event) {
                // Passwort entschlüsseln (oder unverschlüsselt übernehmen)
                try {
                    $wifiPassword = Crypt::decryptString($event->wifi_password);
                } catch (\Exception $e) {
                    $wifiPassword = $event->wifi_password;
                }

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
                $wifiLogoPath = public_path('flow/wifi.png');
                $wifiLogo = file_exists($wifiLogoPath)
                    ? new \Endroid\QrCode\Logo\Logo($wifiLogoPath, 100)
                    : null;

                $wifiResult = $writer->write($wifiQr, $wifiLogo);
                $wifiQrcodeRaw = base64_encode($wifiResult->getString());

                DB::table('event')
                    ->where('id', $eventId)
                    ->update(['wifi_qrcode' => $wifiQrcodeRaw]);
            }
        }

        return response()->json(['success' => true]);
    }


    public function getTableNames(int $eventId)
    {
        $tables = TableEvent::where('event', $eventId)
            ->orderBy('table_number')
            ->get(['table_number', 'table_name']);

        return response()->json([
            'table_names' => $tables,
        ]);
    }

    public function updateTableNames(Request $request, int $eventId)
    {
        $tables = $request->input('table_names');

        if (!is_array($tables)) {
            return response()->json(['error' => 'Ungültiges Format'], 422);
        }

        DB::transaction(function () use ($tables, $eventId) {

            // Alte Tischnamen löschen
            TableEvent::where('event', $eventId)->delete();

            // Neue einfügen
            foreach ($tables as $entry) {
                if (!isset($entry['table_number']) || !isset($entry['table_name'])) {
                    continue;
                }

                TableEvent::create([
                    'event' => $eventId,
                    'table_number' => (int)$entry['table_number'],
                    'table_name' => $entry['table_name'],
                ]);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Geocode an address using OpenStreetMap Nominatim API
     * Proxies the request to avoid CORS issues
     */
    /**
     * Manually check and update attention status for an event
     */
    public function checkAttention(int $eventId): JsonResponse
    {
        $event = Event::find($eventId);

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        try {
            $attentionService = app(EventAttentionService::class);
            $attentionService->updateEventAttentionStatus($eventId);

            // Reload event to get updated status
            $event->refresh();

            return response()->json([
                'success' => true,
                'needs_attention' => $event->needs_attention,
                'needs_attention_checked_at' => $event->needs_attention_checked_at,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to check attention for event {$eventId}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function geocodeAddress(Request $request)
    {
        $request->validate([
            'address' => 'required|string|max:500',
        ]);

        try {
            $address = $request->input('address');
            $result = $this->callGeocodeAPI($address);

            if (!$result) {
                // If the full address didn't work, try the address without the first part
                // typically the first line is a building name, and the remaining part should be a street address
                $parts = explode("\n", $address);
                array_shift($parts); // remove first part
                $address = implode("\n", $parts);

                $result = $this->callGeocodeAPI($address);
            }

            if (!$result) {
                return response()->json([
                    'error' => 'Address not found',
                ], 404);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Geocoding error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Geocoding service unavailable',
            ], 500);
        }
    }

    private function callGeocodeAPI($address)
    {
        $response = Http::withHeaders([
            'User-Agent' => 'FLL Flow Planning Tool (https://github.com/hands-on-leipzig/flow)',
        ])->get('https://nominatim.openstreetmap.org/search', [
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
        ]);

        if ($response->successful() && $response->json()) {
            $data = $response->json();
            if (!empty($data) && isset($data[0])) {
                $result = $data[0];
                return [
                    'lat' => (float)$result['lat'],
                    'lon' => (float)$result['lon'],
                    'display_name' => $result['display_name'] ?? $address,
                ];
            }
        }

        return null;
    }
}
