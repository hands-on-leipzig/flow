<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Team;
use App\Models\MSeason;
use App\Services\PdfLayoutService;
use App\Http\Controllers\Api\DrahtController;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class LabelController extends Controller
{
    private PdfLayoutService $pdfLayoutService;
    private DrahtController $drahtController;

    public function __construct(
        PdfLayoutService $pdfLayoutService,
        DrahtController $drahtController
    ) {
        $this->pdfLayoutService = $pdfLayoutService;
        $this->drahtController = $drahtController;
    }

    /**
     * Generate name tag PDF for team members (Avery L4785 format)
     * 
     * @param int $eventId
     * @return \Illuminate\Http\Response
     */
    public function nameTagsPdf(int $eventId)
    {
        try {
            // Get event with season relationship
            $event = Event::with('seasonRel')->findOrFail($eventId);

            // Get all teams for this event
            $teams = Team::where('event', $eventId)->get();

            if ($teams->isEmpty()) {
                return response()->json(['error' => 'No teams found for this event'], 404);
            }

            // Get season logo
            $seasonLogo = $this->getSeasonLogo($event->seasonRel);

            // Get organizer logos
            $organizerLogos = $this->pdfLayoutService->buildFooterLogos($eventId);

            // Collect all name tags
            $nameTags = [];

            foreach ($teams as $team) {
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

                // Get team members from DRAHT
                $peopleData = $this->getTeamPeopleFromDraht($drahtEventId, $team->team_number_hot);

                if (!$peopleData) {
                    Log::warning("No people data found for team", [
                        'team_id' => $team->id,
                        'team_number_hot' => $team->team_number_hot
                    ]);
                    continue;
                }

                // Get program logo
                $programLogo = $this->getProgramLogo($program);

                // Create name tags for players
                if (!empty($peopleData['players']) && is_array($peopleData['players'])) {
                    foreach ($peopleData['players'] as $player) {
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

                // Create name tags for coaches
                if (!empty($peopleData['coaches']) && is_array($peopleData['coaches'])) {
                    foreach ($peopleData['coaches'] as $coach) {
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
            }

            if (empty($nameTags)) {
                return response()->json(['error' => 'No team members found to generate name tags'], 404);
            }

            // Generate HTML
            $html = view('pdf.name-tags', [
                'nameTags' => $nameTags,
            ])->render();

            // Generate PDF
            $pdf = Pdf::loadHTML($html, 'UTF-8')->setPaper('a4', 'portrait');
            
            // Format date for filename
            $formattedDate = $event->date 
                ? \Carbon\Carbon::parse($event->date)->format('d.m.y')
                : date('d.m.y');
            
            $filename = "FLOW_Namensaufkleber_({$formattedDate}).pdf";

            // Return PDF with headers
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('X-Filename', $filename)
                ->header('Access-Control-Expose-Headers', 'X-Filename');
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
     */
    private function getProgramLogo(?string $program): ?string
    {
        if ($program === 'explore') {
            $logoPath = public_path('flow/fll_explore_hs.png');
        } elseif ($program === 'challenge') {
            $logoPath = public_path('flow/fll_challenge_hs.png');
        } else {
            return null;
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
     * Create name tag data structure
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

        return [
            'person_name' => $personName,
            'team_name' => $teamName,
            'program' => $program,
            'program_logo' => $programLogo,
            'season_logo' => $seasonLogo,
            'organizer_logos' => $organizerLogos,
        ];
    }
}
