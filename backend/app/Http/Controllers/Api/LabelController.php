<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram;
use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MSeason;
use App\Print\DrahtTeamPeople;
use App\Services\LabelPdfService;
use App\Services\PdfDownloadRecorder;
use App\Services\PdfLayoutService;
use App\Support\ProgramCatalog;
use App\Support\StaffingAssignmentLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LabelController extends Controller
{
    public function __construct(
        private PdfLayoutService $pdfLayoutService,
        private LabelPdfService $labelPdfService,
        private PdfDownloadRecorder $downloads,
    ) {}

    public function nameTagsPdf(int $eventId, Request $request)
    {
        try {
            ini_set('memory_limit', '512M');
            $isLocal = app()->environment('local') || config('app.env') === 'local';
            if ($isLocal) {
                ini_set('max_execution_time', 600);
                set_time_limit(600);
            } else {
                ini_set('max_execution_time', 300);
                set_time_limit(300);
            }

            $event = Event::with('seasonRel')->findOrFail($eventId);

            $filters = $request->input('filters', []);
            if (! is_array($filters)) {
                $filters = [];
            }

            $skipOffset = (int) $request->input('skip_offset', 0);
            $skipOffset = max(0, min(9, $skipOffset));

            $logoId = $request->input('logo_id');
            $organizerLogos = [];
            if ($logoId !== null && $logoId !== '') {
                $logoId = (int) $logoId;
                if (! $this->eventOwnsLogo($eventId, $logoId)) {
                    return response()->json([
                        'error' => 'Logo gehört nicht zu diesem Event.',
                    ], 422);
                }
                $organizer = $this->organizerLogoUri($eventId, $logoId);
                if ($organizer) {
                    $organizerLogos = [$organizer];
                }
            }

            $seasonLogo = $this->getSeasonLogo($event->seasonRel);
            $programLogoCache = [];
            $programLogoCache['default'] = $this->getProgramLogo(null);

            $nameTags = $this->collectNameTags($event, $filters, $programLogoCache);

            if ($nameTags === []) {
                return response()->json([
                    'error' => 'Keine Personen gefunden, die den ausgewählten Filtern entsprechen.',
                ], 404);
            }

            $pdfData = $this->labelPdfService->generateNameTags(
                $nameTags,
                $seasonLogo,
                $organizerLogos,
                $programLogoCache,
                false,
                null,
                null,
                $skipOffset,
            );

            if ($pdfData === '' || strlen($pdfData) < 100) {
                throw new \Exception('PDF generation failed: output is empty or invalid');
            }

            $filename = FlowFilename::make($this->filenameStem($filters), 'pdf', $event->date);

            $this->downloads->record($eventId, 'name_tags', $request->user()?->id);

            return response($pdfData, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('X-Filename', $filename)
                ->header('Access-Control-Expose-Headers', 'X-Filename');
        } catch (\Throwable $e) {
            Log::error('Error generating name tags PDF', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate name tags PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $programLogoCache
     * @return list<array{person_name: string, team_name: string, program: string}>
     */
    private function collectNameTags(Event $event, array $filters, array &$programLogoCache): array
    {
        $nameTags = [];
        $programNames = DB::table('m_first_program')->pluck('name', 'id');
        $programSequence = DB::table('m_first_program')->pluck('sequence', 'id');

        if ($this->leafOn($filters, 'cross', 'helpers')) {
            foreach ($this->helperTags($event->id, $filters, 'cross', $programNames, $programLogoCache) as $tag) {
                $nameTags[] = $tag;
            }
        }

        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort(function (int $a, int $b) use ($programSequence) {
                $seq = ((int) ($programSequence[$a] ?? 999)) <=> ((int) ($programSequence[$b] ?? 999));

                return $seq !== 0 ? $seq : ($a <=> $b);
            })
            ->values();

        $peopleByTeam = null;
        $teamsByProgram = null;

        foreach ($programIds as $programId) {
            $scope = 'program:'.$programId;
            $wantCoaches = $this->leafOn($filters, $scope, 'coaches');
            $wantPlayers = $this->leafOn($filters, $scope, 'players');
            $wantHelpers = $this->leafOn($filters, $scope, 'helpers');
            if (! $wantCoaches && ! $wantPlayers && ! $wantHelpers) {
                continue;
            }

            $programKey = $this->programCacheKey($programNames[$programId] ?? null);
            if (! isset($programLogoCache[$programKey])) {
                $programLogoCache[$programKey] = $this->getProgramLogo($programKey);
            }

            if ($wantCoaches || $wantPlayers) {
                if ($peopleByTeam === null) {
                    $peopleByTeam = DrahtTeamPeople::byTeamId($event);
                }
                if ($teamsByProgram === null) {
                    $teamsByProgram = $this->plannedTeamsByProgram((int) $event->id);
                }

                $teams = $teamsByProgram[$programId] ?? [];
                usort($teams, fn ($a, $b) => strcasecmp((string) $a->name, (string) $b->name));
                foreach ($teams as $team) {
                    $people = $peopleByTeam[(int) $team->id] ?? null;
                    if (! is_array($people)) {
                        continue;
                    }
                    if ($wantCoaches) {
                        foreach ($this->sortedPeople($people['coaches'] ?? []) as $person) {
                            $nameTags[] = $this->tagFromPerson($person, (string) $team->name, $programKey);
                        }
                    }
                    if ($wantPlayers) {
                        foreach ($this->sortedPeople($people['players'] ?? []) as $person) {
                            $nameTags[] = $this->tagFromPerson($person, (string) $team->name, $programKey);
                        }
                    }
                }
            }

            if ($wantHelpers) {
                foreach ($this->helperTags($event->id, $filters, $scope, $programNames, $programLogoCache) as $tag) {
                    $nameTags[] = $tag;
                }
            }
        }

        if ($this->leafOn($filters, 'local', 'helpers')) {
            foreach ($this->helperTags($event->id, $filters, 'local', $programNames, $programLogoCache) as $tag) {
                $nameTags[] = $tag;
            }
        }

        return $nameTags;
    }

    /**
     * @return array<int, list<object>>
     */
    private function plannedTeamsByProgram(int $eventId): array
    {
        $planId = (int) (DB::table('plan')->where('event', $eventId)->value('id') ?? 0);
        if ($planId < 1) {
            return [];
        }

        $caps = $this->teamCaps($planId);

        $rows = DB::table('team')
            ->join('team_plan', function ($join) use ($planId) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->where('team_plan.plan', '=', $planId);
            })
            ->where('team.event', $eventId)
            ->where(function ($query) {
                $query->whereNull('team_plan.noshow')
                    ->orWhere('team_plan.noshow', '!=', 1);
            })
            ->orderBy('team.name')
            ->select([
                'team.id',
                'team.name',
                'team.first_program',
                'team.team_number_hot',
                'team_plan.team_number_plan',
            ])
            ->get();

        $byProgram = [];
        foreach ($rows as $row) {
            $fp = (int) $row->first_program;
            if (isset($caps[$fp]) && (int) $row->team_number_plan > $caps[$fp]) {
                continue;
            }
            $byProgram[$fp][] = $row;
        }

        return $byProgram;
    }

    /**
     * @return array<int, int>
     */
    private function teamCaps(int $planId): array
    {
        $names = [
            FirstProgram::CHALLENGE->value => 'c_teams',
            FirstProgram::EXPLORE->value => 'e_teams',
            FirstProgram::FUTURE_8->value => 'f8_teams',
        ];
        $caps = [];
        foreach ($names as $programId => $paramName) {
            $paramId = DB::table('m_parameter')->where('name', $paramName)->value('id');
            if (! $paramId) {
                continue;
            }
            $value = DB::table('plan_param_value')
                ->where('plan', $planId)
                ->where('parameter', $paramId)
                ->value('set_value');
            if ($value === null || $value === '') {
                continue;
            }
            $caps[$programId] = (int) $value;
        }

        return $caps;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $programNames
     * @param  array<string, mixed>  $programLogoCache
     * @return list<array{person_name: string, team_name: string, program: string}>
     */
    private function helperTags(
        int $eventId,
        array $filters,
        string $wantedScope,
        $programNames,
        array &$programLogoCache,
    ): array {
        $assignmentsByPerson = StaffingAssignmentLabel::assignmentsByPerson($eventId);
        $personIds = array_keys($assignmentsByPerson);
        $people = [];
        if ($personIds !== []) {
            $people = DB::table('volunteer_person')
                ->whereIn('id', $personIds)
                ->get(['id', 'first_name', 'last_name'])
                ->keyBy('id');
        }

        $tags = [];
        foreach ($assignmentsByPerson as $personId => $assignments) {
            $person = $people[$personId] ?? null;
            $display = trim((string) ($person->first_name ?? '').' '.(string) ($person->last_name ?? ''));
            if ($display === '') {
                continue;
            }
            foreach ($assignments as $assignment) {
                $scope = $this->assignmentScope($assignment);
                if ($scope !== $wantedScope || ! $this->leafOn($filters, $scope, 'helpers')) {
                    continue;
                }
                $programKey = 'default';
                $fp = $assignment['first_program'] ?? null;
                if ($fp) {
                    $programKey = $this->programCacheKey($programNames[$fp] ?? null);
                }
                if (! isset($programLogoCache[$programKey])) {
                    $programLogoCache[$programKey] = $this->getProgramLogo($programKey === 'default' ? null : $programKey);
                }
                $tags[] = [
                    'person_name' => $display,
                    'team_name' => (string) $assignment['caption'],
                    'program' => $programKey,
                    'last_name' => (string) ($person->last_name ?? ''),
                    'first_name' => (string) ($person->first_name ?? ''),
                    'role_sequence' => $assignment['catalog_sequence'] ?? $assignment['sequence'],
                ];
            }
        }

        usort($tags, function (array $a, array $b) {
            $seq = ((int) $a['role_sequence']) <=> ((int) $b['role_sequence']);
            if ($seq !== 0) {
                return $seq;
            }
            $last = strcasecmp((string) $a['last_name'], (string) $b['last_name']);
            if ($last !== 0) {
                return $last;
            }

            return strcasecmp((string) $a['first_name'], (string) $b['first_name']);
        });

        return array_map(static fn (array $tag) => [
            'person_name' => $tag['person_name'],
            'team_name' => $tag['team_name'],
            'program' => $tag['program'],
        ], $tags);
    }

    /**
     * @param  array{first_program: ?int, is_local: bool}  $assignment
     */
    private function assignmentScope(array $assignment): string
    {
        if (! empty($assignment['is_local'])) {
            return 'local';
        }
        $fp = $assignment['first_program'] ?? null;
        if ($fp === null) {
            return 'cross';
        }

        return 'program:'.(int) $fp;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function leafOn(array $filters, string $scope, string $leaf): bool
    {
        $scopeFilters = $filters[$scope] ?? null;
        if (! is_array($scopeFilters)) {
            return false;
        }

        return filter_var($scopeFilters[$leaf] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filenameStem(array $filters): string
    {
        $hasTeam = false;
        $hasHelper = false;
        foreach ($filters as $scopeFilters) {
            if (! is_array($scopeFilters)) {
                continue;
            }
            if (filter_var($scopeFilters['coaches'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || filter_var($scopeFilters['players'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $hasTeam = true;
            }
            if (filter_var($scopeFilters['helpers'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $hasHelper = true;
            }
        }

        if ($hasHelper && ! $hasTeam) {
            return 'Helferinnen';
        }
        if ($hasTeam && ! $hasHelper) {
            return 'Coaches und Teammitglieder';
        }

        return 'Namensschilder';
    }

    /**
     * @param  mixed  $people
     * @return list<array<string, mixed>>
     */
    private function sortedPeople(mixed $people): array
    {
        if (! is_array($people) || $people === []) {
            return [];
        }
        $normalized = [];
        foreach ($people as $person) {
            if (is_string($person)) {
                $normalized[] = ['name' => $person, 'firstname' => ''];
            } elseif (is_array($person)) {
                $normalized[] = $person;
            }
        }
        usort($normalized, function (array $a, array $b) {
            $last = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            if ($last !== 0) {
                return $last;
            }

            return strcasecmp((string) ($a['firstname'] ?? ''), (string) ($b['firstname'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array{person_name: string, team_name: string, program: string}
     */
    private function tagFromPerson(array $person, string $teamName, string $programKey): array
    {
        $personName = trim((string) ($person['firstname'] ?? '').' '.(string) ($person['name'] ?? ''));
        if ($personName === '') {
            $personName = (string) ($person['name'] ?? 'Unbekannt');
        }

        return [
            'person_name' => $personName,
            'team_name' => $teamName,
            'program' => $programKey,
        ];
    }

    private function programCacheKey(mixed $name): string
    {
        $key = strtolower(trim((string) $name));

        return $key !== '' ? $key : 'default';
    }

    private function eventOwnsLogo(int $eventId, int $logoId): bool
    {
        if ($logoId < 1) {
            return false;
        }

        return DB::table('event_logo')
            ->where('event', $eventId)
            ->where('logo', $logoId)
            ->exists();
    }

    private function organizerLogoUri(int $eventId, int $logoId): ?string
    {
        $logo = DB::table('logo')
            ->join('event_logo', 'event_logo.logo', '=', 'logo.id')
            ->where('event_logo.event', $eventId)
            ->where('logo.id', $logoId)
            ->select('logo.path')
            ->first();

        if (! $logo) {
            return null;
        }

        return $this->pdfLayoutService->toDataUri(storage_path('app/public/'.$logo->path));
    }

    private function getProgramLogo(?string $program): ?string
    {
        $logoPath = ProgramCatalog::logoPath($program, 'hs');
        if (! is_file($logoPath)) {
            return null;
        }

        return $this->pdfLayoutService->toDataUri($logoPath);
    }

    private function getSeasonLogo(?MSeason $season): ?string
    {
        if (! $season || ! $season->name) {
            return null;
        }

        $seasonName = strtolower(str_replace(' ', '_', (string) $season->name));
        $logoPath = public_path('flow/season_'.$seasonName.'_v.png');
        if (! is_file($logoPath)) {
            return null;
        }

        return $this->pdfLayoutService->toDataUri($logoPath);
    }
}
