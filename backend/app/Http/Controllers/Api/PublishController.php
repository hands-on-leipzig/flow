<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MActivityTypeDetail;
use App\Models\OneLinkAccess;
use App\Services\ActivityFetcherService;
use App\Services\PdfLayoutService;
use App\Support\PlanParameter;
use App\Enums\ExploreMode;

use App\Services\SeasonService;
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
            // For existing QR codes, regenerate with ?source=qr if not already present
            // But return the clean display link
            return response()->json([
                'link' => $event->link,  // Clean display link
                'qrcode' => 'data:image/png;base64,' . $event->qrcode,
            ]);
        }

        if (empty($event->name)) {
            return response()->json(['error' => 'Event name is required'], 400);
        }

        switch ($event->level) {

            case 1:
                // Use event name directly
                $link = $event->name;

                // Prüfen, ob mehrere Regio für diesen Regionalpartner existieren
                $eventCount = DB::table('event')
                    ->where('regional_partner', $event->regional_partner)
                    ->where('level', 1)
                    ->where('season', SeasonService::currentSeasonId())
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
                // Find first "-" in event name, add 2 to position, use the rest
                $dashPos = strpos($event->name, '-');
                if ($dashPos !== false) {
                    // Add 2 to skip the "-" and space
                    $position = $dashPos + 2;
                    $suffix = substr($event->name, $position);
                    $link = "quali-" . $suffix;
                } else {
                    // No dash found, use full event name
                    $link = "quali-" . $event->name;
                }
                break;

            case 3:
                $link = "finale"; // Region bewusst weggelassen
        }

        // Link "säubern"
        $link = trim(strtolower($link));
        $link = str_replace(array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', '/', ' '), array('ae', 'oe', 'ue', 'AE', 'OE', 'UE', 'ss', '-', '-'), $link);

        $slug = $link;
        // Display link (stored in DB, shown to users) - clean without query params
        $displayLink = config('app.frontend_url', 'http://localhost:5173') . "/" . $link;
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
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'slug' => $slug,
                'link' => $displayLink,  // Clean link without ?source=qr
                'qrcode' => $qrcodeRaw,
            ]);

        // Update link in DRAHT for both explore and challenge events if they exist
        // Only update DRAHT in production environment
        if (app()->environment('production')) {
            try {
                $drahtController = app(\App\Http\Controllers\Api\DrahtController::class);

                // Update link for challenge event if it exists
                if (!empty($event->event_challenge)) {
                    $drahtController->updateEventLink($event->event_challenge, $displayLink);
                }

                // Update link for explore event if it exists
                if (!empty($event->event_explore)) {
                    $drahtController->updateEventLink($event->event_explore, $displayLink);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the link generation
                Log::error("Failed to update link in DRAHT for event {$event->id}", [
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            // Log that we're skipping DRAHT update in non-production environment
            Log::info("Skipping DRAHT link update for event {$event->id} (environment: " . app()->environment() . ")");
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
            'level' => $level,
            'date' => $event->date,
            'address' => $drahtData['address'] ?? null,
            // hier direkt durchreichen:
            'contact' => $drahtData['contact'] ?? [],
            'teams' => [
                'explore' => [
                    'capacity' => $drahtData['capacity_explore'] ?? 0,
                    'registered' => count($drahtData['teams_explore'] ?? []),
                    'color_hex' => $exploreColor,
                    'list' => $level >= 1 ? array_map(function ($team) {
                        return [
                            'team_number_hot' => $team['ref'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? ''
                        ];
                    }, array_values($drahtData['teams_explore'] ?? [])) : [],
                ],
                'challenge' => [
                    'capacity' => $drahtData['capacity_challenge'] ?? 0,
                    'registered' => count($drahtData['teams_challenge'] ?? []),
                    'color_hex' => $challengeColor,
                    'list' => $level >= 1 ? array_map(function ($team) {
                        return [
                            'team_number_hot' => $team['ref'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? ''
                        ];
                    }, array_values($drahtData['teams_challenge'] ?? [])) : [],
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
        
        // Add explore_group from activity table to each activity
        $activityIds = $activities->pluck('activity_id')->toArray();
        $activityExploreGroups = DB::table('activity')
            ->whereIn('id', $activityIds)
            ->pluck('explore_group', 'id')
            ->toArray();
        
        $activities = $activities->map(function($activity) use ($activityExploreGroups) {
            $activity->explore_group = $activityExploreGroups[$activity->activity_id] ?? null;
            return $activity;
        });

        // Check if there are 2x Explore groups
        $planParams = new PlanParameter($plan->id);
        $eMode = (int) $planParams->get('e_mode');
        $hasTwoExploreGroups = ($eMode === ExploreMode::HYBRID_BOTH->value || $eMode === ExploreMode::DECOUPLED_BOTH->value);

        // Activity Type Details by code (cached lookup with name and sequence)
        $atdCodes = [
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
        ];
        $atdDetails = MActivityTypeDetail::whereIn('code', $atdCodes)->get()->keyBy('code');

        // Helper map: code -> id for quick lookup
        $atdIds = $atdDetails->pluck('id', 'code')->all();

        // Hilfsfunktion: Erste Startzeit und Activity Type Detail für gegebene codes finden
        // Prefer program-specific codes over general codes when multiple are provided
        // Optional filter by explore_group if provided
        $findStart = function ($codes, ?int $exploreGroup = null) use ($activities, $atdIds, $atdDetails) {
            $codeArray = (array)$codes;
            // Sort codes to prefer program-specific (e_/c_/j_/r_) over general (g_) codes
            usort($codeArray, function($a, $b) {
                $aPref = str_starts_with($a, 'g_') ? 1 : 0;
                $bPref = str_starts_with($b, 'g_') ? 1 : 0;
                return $aPref <=> $bPref;
            });
            
            $ids = collect($codeArray)->map(fn($code) => $atdIds[$code] ?? null)->filter();
            
            // Filter activities by explore_group if provided
            $filteredActivities = $activities;
            if ($exploreGroup !== null) {
                $filteredActivities = $activities->filter(fn($a) => 
                    ($a->explore_group ?? null) === $exploreGroup
                );
            }
            
            $act = $filteredActivities->first(fn($a) => $ids->contains($a->activity_type_detail_id));
            if (!$act) {
                return null;
            }
            // Find which code matched this activity (use first matching code, which will be program-specific if available)
            $matchedCode = collect($codeArray)->first(fn($code) => ($atdIds[$code] ?? null) === $act->activity_type_detail_id);
            $atd = $matchedCode ? $atdDetails[$matchedCode] : null;
            return [
                'value' => $act->start_time,
                'label' => $atd->name ?? null,
                'sequence' => $atd->sequence ?? 0,
            ];
        };

        // Hilfsfunktion: Ende der Aktivität (end_time) und Activity Type Detail für gegebene codes
        // Prefer program-specific codes over general codes when multiple are provided
        // Optional filter by explore_group if provided
        $findEnd = function ($codes, ?int $exploreGroup = null) use ($activities, $atdIds, $atdDetails) {
            $codeArray = (array)$codes;
            // Sort codes to prefer program-specific (e_/c_/j_/r_) over general (g_) codes
            usort($codeArray, function($a, $b) {
                $aPref = str_starts_with($a, 'g_') ? 1 : 0;
                $bPref = str_starts_with($b, 'g_') ? 1 : 0;
                return $aPref <=> $bPref;
            });
            
            $ids = collect($codeArray)->map(fn($code) => $atdIds[$code] ?? null)->filter();
            
            // Filter activities by explore_group if provided
            $filteredActivities = $activities;
            if ($exploreGroup !== null) {
                $filteredActivities = $activities->filter(fn($a) => 
                    ($a->explore_group ?? null) === $exploreGroup
                );
            }
            
            $act = $filteredActivities->first(fn($a) => $ids->contains($a->activity_type_detail_id));
            if (!$act) {
                return null;
            }
            // Find which code matched this activity (use first matching code, which will be program-specific if available)
            $matchedCode = collect($codeArray)->first(fn($code) => ($atdIds[$code] ?? null) === $act->activity_type_detail_id);
            $atd = $matchedCode ? $atdDetails[$matchedCode] : null;
            return [
                'value' => $act->end_time,
                'label' => $atd->name ?? null,
                'sequence' => $atd->sequence ?? 0,
            ];
        };

        // Define time entries with labels and sequence for Explore
        if ($hasTwoExploreGroups) {
            // Handle 2x Explore: separate morning (explore_group = 1) and afternoon (explore_group = 2)
            $exploreMorningTimes = [];
            $teamsBriefingMorning = $findStart('e_briefing_coach', 1);
            if ($teamsBriefingMorning && $teamsBriefingMorning['value']) {
                $exploreMorningTimes[] = $teamsBriefingMorning;
            }
            $judgesBriefingMorning = $findStart('e_briefing_judge', 1);
            if ($judgesBriefingMorning && $judgesBriefingMorning['value']) {
                $exploreMorningTimes[] = $judgesBriefingMorning;
            }
            $openingMorning = $findStart(['e_opening', 'g_opening'], 1);
            if ($openingMorning && $openingMorning['value']) {
                $exploreMorningTimes[] = $openingMorning;
            }
            
            // For morning group, calculate end time from awards start + e1_duration_awards parameter
            $awardsStartMorning = $findStart(['e_awards', 'g_awards'], 1);
            $endTimeAdded = false;
            if ($awardsStartMorning && $awardsStartMorning['value']) {
                try {
                    $e1DurationAwards = $planParams->get('e1_duration_awards');
                    if ($e1DurationAwards !== null && $e1DurationAwards !== '' && (int)$e1DurationAwards > 0) {
                        $awardsStartTime = new \DateTime($awardsStartMorning['value']);
                        $awardsStartTime->modify("+" . (int)$e1DurationAwards . " minutes");
                        $exploreMorningTimes[] = [
                            'value' => $awardsStartTime->format('Y-m-d H:i:s'),
                            'label' => 'Ende ca.',
                            'sequence' => 0,
                        ];
                        $endTimeAdded = true;
                    }
                } catch (\RuntimeException $e) {
                    // Parameter doesn't exist, will fall through to use end_time fallback
                } catch (\Exception $e) {
                    // Other exception (e.g., date parsing), will fall through to use end_time fallback
                }
            }
            
            // Fallback: use awards end_time if we didn't add calculated end time
            if (!$endTimeAdded) {
                $endMorning = $findEnd(['e_awards', 'g_awards'], 1);
                if ($endMorning && $endMorning['value']) {
                    $endMorning['label'] = 'Ende ca.';
                    $exploreMorningTimes[] = $endMorning;
                }
            }

            $exploreAfternoonTimes = [];
            $teamsBriefingAfternoon = $findStart('e_briefing_coach', 2);
            if ($teamsBriefingAfternoon && $teamsBriefingAfternoon['value']) {
                $exploreAfternoonTimes[] = $teamsBriefingAfternoon;
            }
            $judgesBriefingAfternoon = $findStart('e_briefing_judge', 2);
            if ($judgesBriefingAfternoon && $judgesBriefingAfternoon['value']) {
                $exploreAfternoonTimes[] = $judgesBriefingAfternoon;
            }
            $openingAfternoon = $findStart(['e_opening', 'g_opening'], 2);
            if ($openingAfternoon && $openingAfternoon['value']) {
                $exploreAfternoonTimes[] = $openingAfternoon;
            }
            $endAfternoon = $findEnd(['e_awards', 'g_awards'], 2);
            if ($endAfternoon && $endAfternoon['value']) {
                $endAfternoon['label'] = 'Ende ca.';
                $exploreAfternoonTimes[] = $endAfternoon;
            }

            // Sort chronologically
            usort($exploreMorningTimes, function($a, $b) {
                return strtotime($a['value']) <=> strtotime($b['value']);
            });
            usort($exploreAfternoonTimes, function($a, $b) {
                return strtotime($a['value']) <=> strtotime($b['value']);
            });
        } else {
            // Single Explore group (explore_group is NULL or not relevant)
            $exploreTimes = [];
            $teamsBriefing = $findStart('e_briefing_coach');
            if ($teamsBriefing && $teamsBriefing['value']) {
                $exploreTimes[] = $teamsBriefing;
            }
            $judgesBriefing = $findStart('e_briefing_judge');
            if ($judgesBriefing && $judgesBriefing['value']) {
                $exploreTimes[] = $judgesBriefing;
            }
            $opening = $findStart(['e_opening', 'g_opening']);
            if ($opening && $opening['value']) {
                $exploreTimes[] = $opening;
            }
            $end = $findEnd(['e_awards', 'g_awards']);
            if ($end && $end['value']) {
                // Override label for the last entry (end time)
                $end['label'] = 'Ende ca.';
                $exploreTimes[] = $end;
            }

            // Sort chronologically by time value (not by sequence)
            usort($exploreTimes, function($a, $b) {
                return strtotime($a['value']) <=> strtotime($b['value']);
            });
        }

        // Define time entries with labels and sequence for Challenge
        $challengeTimes = [];
        $teamsBriefing = $findStart('c_briefing');
        if ($teamsBriefing && $teamsBriefing['value']) {
            $challengeTimes[] = $teamsBriefing;
        }
        $judgesBriefing = $findStart('j_briefing');
        if ($judgesBriefing && $judgesBriefing['value']) {
            $challengeTimes[] = $judgesBriefing;
        }
        $refereesBriefing = $findStart('r_briefing');
        if ($refereesBriefing && $refereesBriefing['value']) {
            $challengeTimes[] = $refereesBriefing;
        }
        $opening = $findStart(['c_opening', 'g_opening']);
        if ($opening && $opening['value']) {
            $challengeTimes[] = $opening;
        }
        $end = $findEnd(['c_awards', 'g_awards']);
        if ($end && $end['value']) {
            // Override label for the last entry (end time)
            $end['label'] = 'Ende ca.';
            $challengeTimes[] = $end;
        }

        // Sort challenge times chronologically
        usort($challengeTimes, function($a, $b) {
            return strtotime($a['value']) <=> strtotime($b['value']);
        });

        $data = [
            'plan_id' => $plan->id,
            'last_change' => $plan->last_change,
            'challenge' => $challengeTimes,
        ];

        // Add explore times based on whether there are 2 groups
        if ($hasTwoExploreGroups) {
            $data['explore_morning'] = $exploreMorningTimes;
            $data['explore_afternoon'] = $exploreAfternoonTimes;
        } else {
            $data['explore'] = $exploreTimes;
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

        // Inhalt + Layout rendern
        $contentHtml = view('pdf.content.qr_codes', [
            'event' => $event,
            'wifi' => $type === 'plan_wifi',
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
