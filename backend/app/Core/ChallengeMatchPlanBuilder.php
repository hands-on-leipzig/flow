<?php

namespace App\Core;

use App\Enums\FirstProgram;
use App\Enums\MatchPlanObjective;
use App\Models\MatchEntry;
use App\Support\MatchPlan;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;

/**
 * Challenge match list: seed from judging alignment, then shared table/rotation steps.
 *
 * Future 8+ will use the same MatchPlan + MatchPlanBuilder with its own N/T
 * and MatchPlanObjective::OPPONENTS. Persist still has no program column —
 * that comes when a second plan is stored.
 */
class ChallengeMatchPlanBuilder
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
            (int) $this->pp('r_tables'),
            (int) $this->pp('j_lanes'),
            MatchPlanObjective::TABLES
        );

        $this->persist($entries);

        return new MatchPlan(
            FirstProgram::CHALLENGE,
            MatchPlanObjective::TABLES,
            $entries
        );
    }

    /**
     * Rounds 0–3 from judging: start team per round, fill matches backwards,
     * 2→4 tables, TR tables from RG1 (Q2), optional empty TR match when r_asym.
     *
     * @return list<array<string, int>>
     */
    private function seedFromJudgingAlignment(): array
    {
        $entries = [];

        // Generate rounds 1 to 3 matching the judging round
        // Then build the test round from round 1
        // - preserve the table assignments
        // - shift matches "backwards" to fit judging round 1

        for ($round = 0; $round <= 3; $round++) {

            if ($round == 0) {
                // TR is easy: Teams starting with judging are last in TR
                $team = $this->pp("j_lanes");
            } else {
                if ($this->pp('g_finale')) {
                    // Finale Day 2: Different team starting positions (no TR on Day 2)
                    switch ($round) {
                        case 1:
                            $team = $this->pp("j_lanes") * 1;  // 1 * 5 = 5
                            break;
                        case 2:
                            $team = $this->pp("j_lanes") * 3;  // 3 * 5 = 15
                            break;
                        case 3:
                            $team = $this->pp("j_lanes") * 4;  // 4 * 5 = 20
                            break;
                    }
                } else {
                    // Normal event team starting positions
                    switch ($this->pp("j_rounds")) {
                        case 4:
                            if ($round < 3) {
                                $team = $this->pp("j_lanes") * ($round + 1);
                            } else {
                                $team = $this->pp("c_teams");
                            }
                            break;

                        case 5:
                            if ($round < 3) {
                                $team = $this->pp("j_lanes") * ($round + 2);
                            } else {
                                $team = $this->pp("c_teams");
                            }
                            break;

                        case 6:
                            $team = $this->pp("j_lanes") * ($round + 2);
                            break;

                            // Not all lanes may be filled in last judging round,
                            // but that does not matter with six rounds, because robot game is aligned with judging 5
                    }

                    // If we have an odd number of teams, start with volunteer
                    if ($team == $this->pp("c_teams") && $this->pp("r_need_volunteer")) {
                        $team = $this->pp("c_teams") + 1;
                    }
                }
            }

            // Fill the match plan for the round starting with the last match, then going backwards
            // Start with just 2 tables. Distribution to 4 tables is done afterwards.

            for ($match = $this->pp("r_matches_per_round"); $match >= 1; $match--) {
                $team_2 = $team;
                $this->getNextTeam($team);
                $team_1 = $team;
                $this->getNextTeam($team);

                $entries[] = [
                    'round'   => $round,
                    'match'   => $match,
                    'table_1' => 1,
                    'table_2' => 2,
                    'team_1'  => ($team_1 > $this->pp("c_teams")) ? 0 : $team_1,   // Change volunteer from $this->pp("c_teams")
                    'team_2'  => ($team_2 > $this->pp("c_teams")) ? 0 : $team_2,   // Change volunteer from $this->pp("c_teams")
                ];
            }

            // With four tables move every second line to the other pair.
            $entries = $this->matchPlanBuilder->assignSideTables($entries, (int) $this->pp("r_tables"));
        }

        // Now, ensure that matches in TR are on the same tables as in RG1
        // This is quality measure Q2

        // Sequence of matches in TR is already correct, but the table assigment must be copied from RG1 to TR

        if (($this->pp("j_lanes") % 2 === 1) && $this->pp("r_tables") == 4 && $this->pp("j_rounds") == 4) {

            // Special case where lanes are (1,3,5), 4 tables and 4 judging rounds
            // Q2 not met, but match plan for TR works!
            // Hits 8 configuations as of Sep 3, 2025
            // TODO

        } else {
            $entries = $this->matchPlanBuilder->copyTablesFromRoundToRound(
                $entries,
                1,
                0,
                (int) $this->pp("r_matches_per_round")
            );
        }

        // Special handling for asymmetric robot games
        if ($this->pp('r_asym') && $this->pp("j_rounds") != 4) {

            // For four tables with asymmetric robot games, we need to do more to prevent the same pair of tables being used twice
            //
            // The issue only happens if r_asym is true
            // This means c_teams = 10, 14, 18, 22 or 26 teams (or one team less)

            // Solution is to add an empty match at tables 3+4 after j_lanes matches
            // This increases the duration of TR by 10 minutes. This is handled when creating the full-day plan

            $newList = [];
            $emptyMatchInserted = false;

            foreach ($entries as $entry) {

                // For TR matches after j_lanes: increment match number to make room for empty match
                if ($entry['round'] === 0 && $entry['match'] > $this->pp("j_lanes")) {
                    $entry['match'] += 1;
                }

                // Copy all modified or unmodified entries
                $newList[] = $entry;

                // Insert empty match right after the last j_lanes match in TR
                if (!$emptyMatchInserted && $entry['round'] === 0 && $entry['match'] === $this->pp("j_lanes")) {
                    $newList[] = [
                        'round'   => 0,
                        'match'   => $this->pp("j_lanes") + 1,
                        'table_1' => 3,
                        'table_2' => 4,
                        'team_1'  => 0,
                        'team_2'  => 0,
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

        // Clear existing match entries for this plan (Challenge-only until match has a program column)
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

        if (!empty($data)) {
            MatchEntry::insert($data);
        }
    }

    private function getNextTeam(&$team): void
    {
        // Get the next team with lower number
        // When 0 is reached cycle to max number
        // Include volunteer team if needed

        $team--;

        if ($team == 0) {
            if ($this->pp("r_need_volunteer")) {
                $team = $this->pp("c_teams") + 1; // Volunteer team
            } else {
                $team = $this->pp("c_teams");
            }
        }
    }
}
