<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Models\Event;
use App\Support\ContaoResultMatch;
use App\Support\ProgramCatalog;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public Contao result links for Explore and Challenge.
 *
 * A link exists only for the FLOW event's own season, and only after Contao
 * has published that season's score.
 */
class ContaoResultLinkService
{
    private const PUBLIC_SCORE_URL = 'https://evaluation.hands-on-technology.org/de/score/';

    /**
     * @return list<array{first_program: int, url: string}>
     */
    public function links(Event $event): array
    {
        $event->loadMissing('seasonRel');
        $label = ContaoResultMatch::seasonLabel(
            $event->seasonRel?->year !== null ? (int) $event->seasonRel->year : null
        );
        if ($label === null) {
            return [];
        }

        $links = [];
        foreach ([FirstProgram::EXPLORE, FirstProgram::CHALLENGE] as $program) {
            $contaoId = ProgramCatalog::contaoId($event, $program->value);
            if (! $contaoId) {
                continue;
            }

            try {
                $url = $this->publishedUrl($event, $program, $label, $contaoId);
            } catch (Exception $e) {
                Log::warning("Contao results link for event {$event->id}: ".$e->getMessage());

                continue;
            }

            if ($url !== null) {
                $links[] = [
                    'first_program' => $program->value,
                    'url' => $url,
                ];
            }
        }

        return array_values(array_unique($links, SORT_REGULAR));
    }

    private function publishedUrl(Event $event, FirstProgram $program, string $label, int $contaoId): ?string
    {
        $type = ContaoResultMatch::seasonType($program);
        if ($type === null) {
            return null;
        }

        $contao = DB::connection('contao');
        $seasons = $contao->table('hot_seasons')
            ->where('type', $type)
            ->where('name', 'like', '%'.$label.'%')
            ->get(['id', 'season_start', 'season_end', 'use_for_evaluation_tool'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'season_start' => $row->season_start,
                'season_end' => $row->season_end,
                'use_for_evaluation_tool' => $row->use_for_evaluation_tool,
            ])
            ->all();

        $seasonId = ContaoResultMatch::pickSeasonId($seasons, $event->date);
        if ($seasonId === null) {
            return null;
        }

        $stored = $contao->table('hot_competitions')
            ->where('id', $contaoId)
            ->first(['id', 'name', 'season']);
        $storedRow = $stored ? [
            'id' => (int) $stored->id,
            'name' => (string) $stored->name,
            'season' => (int) $stored->season,
        ] : null;

        $inSeason = [];
        if ($storedRow === null || $storedRow['season'] !== $seasonId) {
            $inSeason = $contao->table('hot_competitions')
                ->where('season', $seasonId)
                ->get(['id', 'name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                ])
                ->all();
        }

        $regionId = ContaoResultMatch::pickRegionId($storedRow, $seasonId, $inSeason);
        if ($regionId === null) {
            return null;
        }

        $alias = $contao->table('hot_tournament as t')
            ->join('hot_score as s', 's.pid', '=', 't.id')
            ->where('t.region', $regionId)
            ->where('t.season', $seasonId)
            ->where('s.is_published', '1')
            ->where('t.alias', '!=', '')
            ->orderByDesc('t.id')
            ->value('t.alias');

        if (! is_string($alias) || $alias === '') {
            return null;
        }

        return self::PUBLIC_SCORE_URL.rawurlencode($alias);
    }
}
