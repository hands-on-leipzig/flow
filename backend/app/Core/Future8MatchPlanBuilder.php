<?php

namespace App\Core;

use App\Enums\FirstProgram;
use App\Enums\MatchPlanObjective;
use App\Models\MatchEntry;
use App\Support\MatchPlan;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;

/**
 * Future 8+ match list: same judging-alignment seed as Challenge, OPPONENTS objective.
 * Rounds 0–3 only. Afternoon rounds 4–5 are empty on-site slots, not stored here.
 */
class Future8MatchPlanBuilder
{
    use UsesPlanParameter;

    private MatchPlanBuilder $matchPlanBuilder;

    public function __construct(PlanParameter $params)
    {
        $this->params = $params;
        $this->matchPlanBuilder = new MatchPlanBuilder;
    }

    public function build(): MatchPlan
    {
        $entries = $this->seedFromJudgingAlignment();
        $entries = $this->matchPlanBuilder->rotateRounds2And3(
            $entries,
            (int) $this->pp('f8_fields'),
            (int) $this->pp('f8_lanes'),
            MatchPlanObjective::OPPONENTS
        );

        $this->persist($entries);

        return new MatchPlan(
            FirstProgram::FUTURE_8,
            MatchPlanObjective::OPPONENTS,
            $entries
        );
    }

    /**
     * @return list<array<string, int>>
     */
    private function seedFromJudgingAlignment(): array
    {
        $entries = [];

        for ($round = 0; $round <= 3; $round++) {

            if ($round == 0) {
                $team = $this->pp('f8_lanes');
            } else {
                switch ($this->pp('f8_j_rounds')) {
                    case 4:
                        if ($round < 3) {
                            $team = $this->pp('f8_lanes') * ($round + 1);
                        } else {
                            $team = $this->pp('f8_teams');
                        }
                        break;

                    case 5:
                        if ($round < 3) {
                            $team = $this->pp('f8_lanes') * ($round + 2);
                        } else {
                            $team = $this->pp('f8_teams');
                        }
                        break;

                    case 6:
                        $team = $this->pp('f8_lanes') * ($round + 2);
                        break;
                }

                if ($team == $this->pp('f8_teams') && $this->pp('f8_r_need_volunteer')) {
                    $team = $this->pp('f8_teams') + 1;
                }
            }

            for ($match = $this->pp('f8_r_matches_per_round'); $match >= 1; $match--) {
                $team_2 = $team;
                $this->getNextTeam($team);
                $team_1 = $team;
                $this->getNextTeam($team);

                $entries[] = [
                    'round' => $round,
                    'match' => $match,
                    'table_1' => 1,
                    'table_2' => 2,
                    'team_1' => ($team_1 > $this->pp('f8_teams')) ? 0 : $team_1,
                    'team_2' => ($team_2 > $this->pp('f8_teams')) ? 0 : $team_2,
                ];
            }

            $entries = $this->matchPlanBuilder->assignSideTables($entries, (int) $this->pp('f8_fields'));
        }

        if (($this->pp('f8_lanes') % 2 === 1) && $this->pp('f8_fields') == 4 && $this->pp('f8_j_rounds') == 4) {
            // Same special case as Challenge: Q2 not met; TR still works.
        } else {
            $entries = $this->matchPlanBuilder->copyTablesFromRoundToRound(
                $entries,
                1,
                0,
                (int) $this->pp('f8_r_matches_per_round')
            );
        }

        if ($this->pp('f8_r_asym') && $this->pp('f8_j_rounds') != 4) {
            $newList = [];
            $emptyMatchInserted = false;

            foreach ($entries as $entry) {
                if ($entry['round'] === 0 && $entry['match'] > $this->pp('f8_lanes')) {
                    $entry['match'] += 1;
                }

                $newList[] = $entry;

                if (! $emptyMatchInserted && $entry['round'] === 0 && $entry['match'] === $this->pp('f8_lanes')) {
                    $newList[] = [
                        'round' => 0,
                        'match' => $this->pp('f8_lanes') + 1,
                        'table_1' => 3,
                        'table_2' => 4,
                        'team_1' => 0,
                        'team_2' => 0,
                    ];
                    $emptyMatchInserted = true;
                }
            }

            $entries = $newList;
        }

        return $entries;
    }

    /**
     * @param list<array<string, int>> $entries
     */
    private function persist(array $entries): void
    {
        $planId = $this->pp('g_plan');

        MatchEntry::where('plan', $planId)->delete();

        $data = array_map(function ($entry) use ($planId) {
            return [
                'plan' => $planId,
                'round' => $entry['round'],
                'match_no' => $entry['match'],
                'table_1' => $entry['table_1'],
                'table_2' => $entry['table_2'],
                'table_1_team' => $entry['team_1'],
                'table_2_team' => $entry['team_2'],
            ];
        }, $entries);

        if (! empty($data)) {
            MatchEntry::insert($data);
        }
    }

    private function getNextTeam(&$team): void
    {
        $team--;

        if ($team == 0) {
            if ($this->pp('f8_r_need_volunteer')) {
                $team = $this->pp('f8_teams') + 1;
            } else {
                $team = $this->pp('f8_teams');
            }
        }
    }
}
