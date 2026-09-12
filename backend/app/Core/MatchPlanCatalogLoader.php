<?php

namespace App\Core;

use App\Enums\FirstProgram;
use App\Models\MatchEntry;
use App\Models\MMatch;
use App\Support\MatchPlan;
use RuntimeException;

/**
 * Future 8+ match list from catalog m_match (not MatchPlanBuilder).
 *
 * Challenge keeps the builder. Catalog is SoT for pairings; Future prepareMain
 * sets f8_j_rounds from max(round)+1 after load.
 */
class MatchPlanCatalogLoader
{
    public function load(FirstProgram $program, int $planId, int $teams, int $lanes, int $tables): MatchPlan
    {
        if ($program !== FirstProgram::FUTURE_8) {
            throw new RuntimeException(
                'MatchPlanCatalogLoader: only Future 8+ loads from m_match; Challenge stays on MatchPlanBuilder.'
            );
        }

        $rows = $this->rowsForKey($program->value, $teams, $lanes, $tables);
        $remapVolunteer = false;

        if ($rows === []) {
            $rows = $this->rowsForKey($program->value, $teams + 1, $lanes, $tables);
            $remapVolunteer = $rows !== [];
        }

        if ($rows === []) {
            throw new RuntimeException(
                "Kein Future 8+ Matchplan in m_match für Teams={$teams}, Jurygruppen={$lanes}, Spielfelder={$tables}"
                ." (auch nicht Teams+1=".($teams + 1).').'
            );
        }

        $volunteerTeam = $teams + 1;
        $entries = [];
        foreach ($rows as $row) {
            $team1 = (int) $row->table_1_team;
            $team2 = (int) $row->table_2_team;
            if ($remapVolunteer) {
                if ($team1 === $volunteerTeam) {
                    $team1 = 0;
                }
                if ($team2 === $volunteerTeam) {
                    $team2 = 0;
                }
            }

            $entries[] = [
                'round' => (int) $row->round,
                'match' => (int) $row->match_no,
                'table_1' => (int) $row->table_1,
                'table_2' => (int) $row->table_2,
                'team_1' => $team1,
                'team_2' => $team2,
            ];
        }

        $this->persist($planId, $program->value, $entries);

        return new MatchPlan($program, $entries);
    }

    /**
     * @return list<object>
     */
    private function rowsForKey(int $programId, int $teams, int $lanes, int $tables): array
    {
        return MMatch::query()
            ->where('first_program', $programId)
            ->where('teams', $teams)
            ->where('lanes', $lanes)
            ->where('tables', $tables)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get()
            ->all();
    }

    /**
     * Replace live match rows for this plan + program (same scope as MatchPlanBuilder).
     *
     * @param  list<array{round: int, match: int, table_1: int, table_2: int, team_1: int, team_2: int}>  $entries
     */
    private function persist(int $planId, int $programId, array $entries): void
    {
        MatchEntry::where('plan', $planId)
            ->where('first_program', $programId)
            ->delete();

        $data = array_map(function (array $entry) use ($planId, $programId) {
            return [
                'plan' => $planId,
                'first_program' => $programId,
                'round' => $entry['round'],
                'match_no' => $entry['match'],
                'table_1' => $entry['table_1'],
                'table_2' => $entry['table_2'],
                'table_1_team' => $entry['team_1'],
                'table_2_team' => $entry['team_2'],
            ];
        }, $entries);

        if ($data !== []) {
            MatchEntry::insert($data);
        }
    }
}
