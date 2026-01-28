<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Team;
use App\Models\MSeason;
use App\Services\PdfLayoutService;
use App\Services\LabelPdfService;
use App\Services\EventTitleService;
use App\Http\Controllers\Api\DrahtController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class LabelController extends Controller
{
    private PdfLayoutService $pdfLayoutService;
    private LabelPdfService $labelPdfService;
    private EventTitleService $eventTitleService;
    private DrahtController $drahtController;

    public function __construct(
        PdfLayoutService $pdfLayoutService,
        LabelPdfService $labelPdfService,
        EventTitleService $eventTitleService,
        DrahtController $drahtController
    ) {
        $this->pdfLayoutService = $pdfLayoutService;
        $this->labelPdfService = $labelPdfService;
        $this->eventTitleService = $eventTitleService;
        $this->drahtController = $drahtController;
    }

    /**
     * Generate name tag PDF for team members (Avery L4785 format)
     * 
     * @param int $eventId
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function nameTagsPdf(int $eventId, Request $request)
    {
        try {
            // Increase memory limit for PDF generation with many images
            ini_set('memory_limit', '512M');
            
            // Increase timeout for local installations with slow internet
            // Check if running in local environment
            $isLocal = app()->environment('local') || config('app.env') === 'local';
            if ($isLocal) {
                ini_set('max_execution_time', 600); // 10 minutes for local
                set_time_limit(600);
            } else {
                ini_set('max_execution_time', 300); // 5 minutes for production
                set_time_limit(300);
            }
            
            // Get event with season relationship
            $event = Event::with('seasonRel')->findOrFail($eventId);

            // Get filter parameters: program_filters structure { programId: { players: bool, coaches: bool } }
            $programFilters = $request->input('program_filters', []);
            
            // If no filters provided, default to including all
            if (empty($programFilters) || !is_array($programFilters)) {
                $programFilters = [];
            }
            
            // Get skip offset (0-9) to skip labels at the start
            $skipOffset = (int)$request->input('skip_offset', 0);
            $skipOffset = max(0, min(9, $skipOffset)); // Clamp between 0 and 9
            
            // Extract program IDs from filters
            $programIds = array_keys($programFilters);
            $programIds = array_map('intval', $programIds);

            // Get plan for this event
            $plan = DB::table('plan')
                ->where('event', $eventId)
                ->select('id')
                ->first();

            // Get c_teams parameter value if plan exists
            $cTeams = null;
            if ($plan) {
                $cTeamsParamId = DB::table('m_parameter')
                    ->where('name', 'c_teams')
                    ->value('id');
                
                if ($cTeamsParamId) {
                    $cTeams = DB::table('plan_param_value')
                        ->where('plan', $plan->id)
                        ->where('parameter', $cTeamsParamId)
                        ->value('set_value');
                    $cTeams = $cTeams ? (int)$cTeams : null;
                }
            }

            // Get teams for this event, filtered by program and excluding noshow/overflow teams
            $teamsQuery = DB::table('team')
                ->join('m_first_program', 'team.first_program', '=', 'm_first_program.id')
                ->where('team.event', $eventId)
                ->select('team.*');
            
            // Filter by program IDs if provided
            if (!empty($programIds)) {
                $teamsQuery->whereIn('team.first_program', $programIds);
            }
            
            // Join with team_plan to filter out excluded teams
            if ($plan) {
                $teamsQuery->leftJoin('team_plan', function($join) use ($plan) {
                    $join->on('team.id', '=', 'team_plan.team')
                         ->where('team_plan.plan', '=', $plan->id);
                });
                
                // Exclude teams with noshow = 1
                // Include teams that don't have a team_plan entry (not yet in plan) or noshow != 1
                $teamsQuery->where(function($query) {
                    $query->whereNull('team_plan.noshow')  // No team_plan entry
                          ->orWhere('team_plan.noshow', '!=', 1);  // noshow != 1 (includes 0 and other values)
                });
                
                // Exclude teams where team_number_plan > c_teams (if c_teams is set)
                // Include teams that don't have a team_plan entry (not yet in plan)
                if ($cTeams !== null) {
                    $teamsQuery->where(function($query) use ($cTeams) {
                        $query->whereNull('team_plan.team_number_plan')  // No team_plan entry
                              ->orWhere('team_plan.team_number_plan', '<=', $cTeams);  // Within planned range
                    });
                }
            }
            
            $teams = $teamsQuery->orderBy('m_first_program.sequence')
                ->orderBy('team.name')
                ->get();
            
            // Convert to Team models for compatibility with existing code
            $teamModels = collect($teams)->map(function($team) {
                return Team::find($team->id);
            })->filter();

            if ($teamModels->isEmpty()) {
                return response()->json(['error' => 'No teams found for this event'], 404);
            }

            // Get season logo (load once, reuse for all tags)
            $seasonLogo = $this->getSeasonLogo($event->seasonRel);

            // Get organizer logos (load once, reuse for all tags)
            // Only use the first logo by sort_order
            $organizerLogos = $this->getFirstOrganizerLogo($eventId);

            // Collect all name tags
            $nameTags = [];
            
            // Cache program logos to avoid loading the same logo multiple times
            $programLogoCache = [];
            
            // Cache DRAHT people data per program to avoid multiple API calls
            // Key: drahtEventId, Value: all people data for that event
            $drahtPeopleCache = [];

            foreach ($teamModels as $team) {
                // Determine program and DRAHT event ID
                $program = $this->getProgramFromTeam($team);
                $drahtEventId = $this->getDrahtEventId($event, $program);

                if (!$drahtEventId) {
                    Log::warning("No DRAHT event ID found for team", [
                        'team_id' => $team->id,
                        'program' => $program
                    ]);
                    continue;
                }

                // Fetch all people data for this DRAHT event once (cache per event)
                if (!isset($drahtPeopleCache[$drahtEventId])) {
                    try {
                        $response = $this->drahtController->getPeople($drahtEventId);
                        $statusCode = $response->getStatusCode();
                        if ($statusCode === 200) {
                            $allPeopleData = $response->getData(true);
                            $drahtPeopleCache[$drahtEventId] = is_array($allPeopleData) ? $allPeopleData : [];
                        } else {
                            Log::warning("Failed to fetch people data from DRAHT", [
                                'draht_event_id' => $drahtEventId,
                                'status' => $statusCode
                            ]);
                            $drahtPeopleCache[$drahtEventId] = [];
                        }
                    } catch (\Exception $e) {
                        Log::error('Error fetching people data from DRAHT', [
                            'draht_event_id' => $drahtEventId,
                            'error' => $e->getMessage()
                        ]);
                        $drahtPeopleCache[$drahtEventId] = [];
                    }
                }

                // Get team members from cached DRAHT data
                $allPeopleData = $drahtPeopleCache[$drahtEventId];
                $peopleData = null;
                
                if ($team->team_number_hot && isset($allPeopleData[$team->team_number_hot])) {
                    $peopleData = $allPeopleData[$team->team_number_hot];
                } elseif ($team->team_number_hot && isset($allPeopleData[(string)$team->team_number_hot])) {
                    $peopleData = $allPeopleData[(string)$team->team_number_hot];
                }

                if (!$peopleData) {
                    Log::warning("No people data found for team", [
                        'team_id' => $team->id,
                        'team_number_hot' => $team->team_number_hot
                    ]);
                    continue;
                }

                // Get program logo (use cache to avoid loading same logo multiple times)
                if (!isset($programLogoCache[$program])) {
                    $programLogoCache[$program] = $this->getProgramLogo($program);
                }
                $programLogo = $programLogoCache[$program];

                // Get filter settings for this team's program
                $teamProgramId = $team->first_program;
                $includePlayers = true; // Default
                $includeCoaches = true; // Default
                
                if (isset($programFilters[$teamProgramId])) {
                    $filters = $programFilters[$teamProgramId];
                    $includePlayers = filter_var($filters['players'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $includeCoaches = filter_var($filters['coaches'] ?? true, FILTER_VALIDATE_BOOLEAN);
                }

                // Create name tags for coaches first (if enabled for this program)
                if ($includeCoaches && !empty($peopleData['coaches']) && is_array($peopleData['coaches'])) {
                    // Sort coaches alphabetically by last name, then first name
                    $coaches = $peopleData['coaches'];
                    usort($coaches, function($a, $b) {
                        // Handle string format (full name)
                        if (is_string($a) && is_string($b)) {
                            return strcasecmp($a, $b);
                        }
                        if (is_string($a)) {
                            $a = ['name' => $a, 'firstname' => ''];
                        }
                        if (is_string($b)) {
                            $b = ['name' => $b, 'firstname' => ''];
                        }
                        
                        // Sort by last name first, then first name
                        $lastNameA = $a['name'] ?? '';
                        $lastNameB = $b['name'] ?? '';
                        $lastNameCompare = strcasecmp($lastNameA, $lastNameB);
                        
                        if ($lastNameCompare !== 0) {
                            return $lastNameCompare;
                        }
                        
                        // If last names are equal, sort by first name
                        $firstNameA = $a['firstname'] ?? '';
                        $firstNameB = $b['firstname'] ?? '';
                        return strcasecmp($firstNameA, $firstNameB);
                    });
                    
                    foreach ($coaches as $coach) {
                        // Handle both object and string coach formats
                        if (is_string($coach)) {
                            $coach = ['name' => $coach];
                        }
                        $nameTags[] = $this->createNameTagData(
                            $coach,
                            $team->name,
                            $program,
                            $programLogo,
                            $seasonLogo,
                            $organizerLogos
                        );
                    }
                }

                // Create name tags for players (if enabled for this program)
                if ($includePlayers && !empty($peopleData['players']) && is_array($peopleData['players'])) {
                    // Sort players alphabetically by last name, then first name
                    $players = $peopleData['players'];
                    usort($players, function($a, $b) {
                        // Handle string format (full name)
                        if (is_string($a) && is_string($b)) {
                            return strcasecmp($a, $b);
                        }
                        if (is_string($a)) {
                            $a = ['name' => $a, 'firstname' => ''];
                        }
                        if (is_string($b)) {
                            $b = ['name' => $b, 'firstname' => ''];
                        }
                        
                        // Sort by last name first, then first name
                        $lastNameA = $a['name'] ?? '';
                        $lastNameB = $b['name'] ?? '';
                        $lastNameCompare = strcasecmp($lastNameA, $lastNameB);
                        
                        if ($lastNameCompare !== 0) {
                            return $lastNameCompare;
                        }
                        
                        // If last names are equal, sort by first name
                        $firstNameA = $a['firstname'] ?? '';
                        $firstNameB = $b['firstname'] ?? '';
                        return strcasecmp($firstNameA, $firstNameB);
                    });
                    
                    foreach ($players as $player) {
                        $nameTags[] = $this->createNameTagData(
                            $player,
                            $team->name,
                            $program,
                            $programLogo,
                            $seasonLogo,
                            $organizerLogos
                        );
                    }
                }
            }

            if (empty($nameTags)) {
                return response()->json([
                    'error' => 'No team members found to generate name tags',
                    'message' => 'Keine Teammitglieder gefunden, die den ausgewählten Filtern entsprechen. Bitte Filter anpassen.'
                ], 404);
            }

            // Generate header text for team labels
            $headerLeft = 'Team-Liste';
            $headerRight = 'Sortierung: Teamname, Coach:innen > Teammitglieder, alphabetisch nach Namen';
            
            // Generate PDF using TCPDF for precise positioning
            try {
                $pdfData = $this->labelPdfService->generateNameTags(
                    $nameTags,
                    $seasonLogo,
                    $organizerLogos,
                    $programLogoCache,
                    false, // showBorders
                    $headerLeft, // headerLeft
                    $headerRight, // headerRight
                    $skipOffset // skipOffset
                );
                
                if (empty($pdfData) || strlen($pdfData) < 100) {
                    Log::error('Generated PDF is empty or too small', [
                        'size' => strlen($pdfData ?? ''),
                        'event_id' => $eventId
                    ]);
                    throw new \Exception('PDF generation failed: output is empty or invalid');
                }
                
                // Format date for filename
                $formattedDate = $event->date 
                    ? \Carbon\Carbon::parse($event->date)->format('d.m.y')
                    : date('d.m.y');
                
                $filename = "FLOW_Aufkleber_Teams_({$formattedDate}).pdf";

                // Return PDF with headers
                return response($pdfData, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('X-Filename', $filename)
                    ->header('Access-Control-Expose-Headers', 'X-Filename');
            } catch (\Exception $pdfException) {
                Log::error('Error generating PDF with TCPDF', [
                    'event_id' => $eventId,
                    'error' => $pdfException->getMessage(),
                    'trace' => $pdfException->getTraceAsString()
                ]);
                throw $pdfException; // Re-throw to be caught by outer catch
            }
        } catch (\Exception $e) {
            Log::error('Error generating name tags PDF', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate name tags PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get program type from team
     */
    private function getProgramFromTeam(Team $team): ?string
    {
        // Get program name from m_first_program table
        $program = DB::table('m_first_program')
            ->where('id', $team->first_program)
            ->value('name');

        if (!$program) {
            return null;
        }

        // Map to lowercase program name
        $programLower = strtolower($program);
        if ($programLower === 'explore') {
            return 'explore';
        } elseif ($programLower === 'challenge') {
            return 'challenge';
        }

        return null;
    }

    /**
     * Get DRAHT event ID based on program
     */
    private function getDrahtEventId(Event $event, ?string $program): ?int
    {
        if ($program === 'explore' && $event->event_explore) {
            return $event->event_explore;
        } elseif ($program === 'challenge' && $event->event_challenge) {
            return $event->event_challenge;
        }

        return null;
    }

    /**
     * Get team people data from DRAHT API
     */
    private function getTeamPeopleFromDraht(int $drahtEventId, ?int $teamNumberHot): ?array
    {
        try {
            $response = $this->drahtController->getPeople($drahtEventId);
            
            // getPeople returns JsonResponse, get the data
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return null;
            }

            $allPeopleData = $response->getData(true);
            
            if (!is_array($allPeopleData)) {
                return null;
            }
            
            // Find team data by team_number_hot
            if ($teamNumberHot && isset($allPeopleData[$teamNumberHot])) {
                return $allPeopleData[$teamNumberHot];
            }

            // Also try string key
            if ($teamNumberHot && isset($allPeopleData[(string)$teamNumberHot])) {
                return $allPeopleData[(string)$teamNumberHot];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching people data from DRAHT', [
                'draht_event_id' => $drahtEventId,
                'team_number_hot' => $teamNumberHot,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get program logo as data URI
     * Returns Explore, Challenge, or default FLL logo if no program specified
     */
    private function getProgramLogo(?string $program): ?string
    {
        if ($program === 'explore') {
            $logoPath = public_path('flow/fll_explore_hs.png');
        } elseif ($program === 'challenge') {
            $logoPath = public_path('flow/fll_challenge_hs.png');
        } else {
            // Default FLL logo when no specific program is chosen
            // Try horizontal small version first, fallback to vertical
            $defaultPaths = [
                public_path('flow/first+fll_hs.png'),
                public_path('flow/first+fll_h.png'),
                public_path('flow/first+fll_v.png'),
            ];
            
            $logoPath = null;
            foreach ($defaultPaths as $path) {
                if (file_exists($path)) {
                    $logoPath = $path;
                    break;
                }
            }
            
            if (!$logoPath) {
                return null;
            }
        }

        return $this->pdfLayoutService->toDataUri($logoPath);
    }

    /**
     * Get season logo as data URI
     */
    private function getSeasonLogo(?MSeason $season): ?string
    {
        if (!$season || !$season->name) {
            return null;
        }

        // Map season name to logo filename
        // Convert season name to lowercase and replace spaces with underscores
        $seasonName = strtolower($season->name);
        $seasonName = str_replace(' ', '_', $seasonName);
        
        // Try common season logo filenames
        $possibleFilenames = [
            "season_{$seasonName}_v.png",
            "season_{$seasonName}_wordmark.png",
            "season_{$seasonName}.png",
        ];

        foreach ($possibleFilenames as $filename) {
            $logoPath = public_path("flow/{$filename}");
            if (file_exists($logoPath)) {
                return $this->pdfLayoutService->toDataUri($logoPath);
            }
        }

        // Fallback: try to find any season logo file
        $flowDir = public_path('flow');
        if (is_dir($flowDir)) {
            $files = glob($flowDir . '/season_*.png');
            if (!empty($files)) {
                // Use the first season logo found as fallback
                return $this->pdfLayoutService->toDataUri($files[0]);
            }
        }

        return null;
    }

    /**
     * Get the first organizer logo by sort_order
     */
    private function getFirstOrganizerLogo(int $eventId): array
    {
        $logo = DB::table('logo')
            ->join('event_logo', 'event_logo.logo', '=', 'logo.id')
            ->where('event_logo.event', $eventId)
            ->orderBy('event_logo.sort_order')
            ->select('logo.path')
            ->first();

        if (!$logo) {
            return [];
        }

        $path = storage_path('app/public/' . $logo->path);
        $uri = $this->pdfLayoutService->toDataUri($path);
        
        return $uri ? [$uri] : [];
    }

    /**
     * Create name tag data structure
     * Note: Logos are not stored here to save memory - they're passed separately to template
     */
    private function createNameTagData(
        array $person,
        string $teamName,
        ?string $program,
        ?string $programLogo,
        ?string $seasonLogo,
        array $organizerLogos
    ): array {
        // Format person name (firstname + name)
        $personName = trim(($person['firstname'] ?? '') . ' ' . ($person['name'] ?? ''));
        if (empty($personName)) {
            // Fallback to just name if no firstname
            $personName = $person['name'] ?? 'Unbekannt';
        }

        // Only store minimal data - logos are passed separately to template to avoid duplication
        return [
            'person_name' => $personName,
            'team_name' => $teamName,
            'program' => $program,
        ];
    }

    /**
     * Generate name tag PDF for volunteers (Avery L4785 format)
     * 
     * @param int $eventId
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function volunteerLabelsPdf(int $eventId, Request $request)
    {
        try {
            // Increase memory limit for PDF generation with many images
            ini_set('memory_limit', '512M');
            
            // Validate request
            $validated = $request->validate([
                'volunteers' => 'required|array|min:1',
                'volunteers.*.name' => 'required|string',
                'volunteers.*.role' => 'required|string',
                'volunteers.*.program' => 'nullable|string|in:E,C,',
                'skip_offset' => 'nullable|integer|min:0|max:9',
            ]);
            
            // Get skip offset (0-9) to skip labels at the start
            $skipOffset = (int)($validated['skip_offset'] ?? 0);
            $skipOffset = max(0, min(9, $skipOffset)); // Clamp between 0 and 9
            
            $volunteers = $validated['volunteers'];
            
            // Get event with season relationship
            $event = Event::with('seasonRel')->findOrFail($eventId);
            
            // Get season logo (load once, reuse for all tags)
            $seasonLogo = $this->getSeasonLogo($event->seasonRel);
            
            // Get organizer logos (load once, reuse for all tags)
            // Only use the first logo by sort_order
            $organizerLogos = $this->getFirstOrganizerLogo($eventId);
            
            // Cache program logos (including default FLL logo for volunteers without program)
            $programLogoCache = [];
            $programLogoCache['explore'] = $this->getProgramLogo('explore');
            $programLogoCache['challenge'] = $this->getProgramLogo('challenge');
            $programLogoCache['default'] = $this->getProgramLogo(null); // Default FLL logo
            
            // Convert volunteers to name tag format
            $nameTags = [];
            foreach ($volunteers as $volunteer) {
                // Map program: E -> explore, C -> challenge, other/empty -> default
                $program = 'default';
                if ($volunteer['program'] === 'E') {
                    $program = 'explore';
                } elseif ($volunteer['program'] === 'C') {
                    $program = 'challenge';
                }
                
                $nameTags[] = [
                    'person_name' => $volunteer['name'],
                    'team_name' => $volunteer['role'], // Use role instead of team name
                    'program' => $program,
                ];
            }
            
            if (empty($nameTags)) {
                return response()->json(['error' => 'No volunteers provided'], 400);
            }
            
            // Generate header text for volunteer labels
            $headerLeft = 'Volunteers';
            $headerRight = 'Sortierung wie vom Veranstalter eingeben';
            
            // Generate PDF using TCPDF for precise positioning
            try {
                $pdfData = $this->labelPdfService->generateNameTags(
                    $nameTags,
                    $seasonLogo,
                    $organizerLogos,
                    $programLogoCache,
                    false, // showBorders
                    $headerLeft, // headerLeft
                    $headerRight, // headerRight
                    $skipOffset // skipOffset
                );
                
                if (empty($pdfData) || strlen($pdfData) < 100) {
                    Log::error('Generated PDF is empty or too small', [
                        'size' => strlen($pdfData ?? ''),
                        'event_id' => $eventId
                    ]);
                    throw new \Exception('PDF generation failed: output is empty or invalid');
                }
                
                // Format date for filename
                $formattedDate = $event->date 
                    ? \Carbon\Carbon::parse($event->date)->format('d.m.y')
                    : date('d.m.y');
                
                $filename = "FLOW_Aufkleber_Volunteers_({$formattedDate}).pdf";

                // Return PDF with headers
                return response($pdfData, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('X-Filename', $filename)
                    ->header('Access-Control-Expose-Headers', 'X-Filename');
            } catch (\Exception $pdfException) {
                Log::error('Error generating PDF with TCPDF', [
                    'event_id' => $eventId,
                    'error' => $pdfException->getMessage(),
                    'trace' => $pdfException->getTraceAsString()
                ]);
                throw $pdfException; // Re-throw to be caught by outer catch
            }
        } catch (\Exception $e) {
            Log::error('Error generating volunteer labels PDF', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate volunteer labels PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
