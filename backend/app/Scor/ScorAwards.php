<?php

namespace App\Scor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One trophy among Champion, Silber and Bronze, and one among Projekt, Technisches Design and Grundwerte.
 * Champion excludes Projekt, Technisches Design and Grundwerte; Silber and Bronze do not.
 * Spiel and Coaching may be added. A team holds at most three trophies.
 * Each judged category has at most four nominations.
 * Champion, Silber and Bronze follow the overall ranking and need no nomination.
 * Coaching goes to every team of the chosen coach.
 */
final class ScorAwards
{
    /** @var list<string> */
    public const PODIUM = ['champion', 'silver', 'bronze'];

    /** @var list<string> */
    public const JUDGED = [...self::PODIUM, 'research', 'robotdesign', 'core_values'];

    /** @var list<string> */
    public const NOMINATED = ['research', 'robotdesign', 'core_values'];

    /**
     * @param  list<array{team: int, category: string}>  $trophies
     * @param  list<array{team: int, category: string}>  $nominations
     * @param  list<int>  $coachingTeams
     */
    public function assertLegal(array $trophies, array $nominations, array $coachingTeams): void
    {
        $perTeam = [];
        foreach ($trophies as $row) {
            $team = (int) $row['team'];
            $category = (string) $row['category'];
            if (!in_array($category, [...self::JUDGED, 'robot'], true)) {
                throw new ScorAccessException('Unbekannte Pokal-Kategorie.', 422);
            }
            $perTeam[$team][] = $category;
        }
        foreach ($coachingTeams as $team) {
            $perTeam[(int) $team][] = 'coaching';
        }

        foreach ($perTeam as $categories) {
            if (count($categories) !== count(array_unique($categories))) {
                throw new ScorAccessException('Dieselbe Auszeichnung steht bei einem Team nur einmal.', 422);
            }
            $places = array_intersect($categories, self::PODIUM);
            $judged = array_intersect($categories, self::NOMINATED);
            if (count($places) > 1) {
                throw new ScorAccessException('Ein Team bekommt nur einen Platz aus Champion, Silber und Bronze.', 422);
            }
            if (count($judged) > 1) {
                throw new ScorAccessException('Ein Team bekommt nur einen Pokal aus Projekt, Technisches Design und Grundwerten.', 422);
            }
            if (in_array('champion', $places, true) && $judged !== []) {
                throw new ScorAccessException('Der Champion bekommt keinen Pokal aus Projekt, Technisches Design und Grundwerten.', 422);
            }
            if (count($categories) > 3) {
                throw new ScorAccessException('Ein Team bekommt höchstens drei Pokale.', 422);
            }
        }

        $counts = [];
        foreach ($nominations as $row) {
            $category = (string) $row['category'];
            if (!in_array($category, self::NOMINATED, true)) {
                throw new ScorAccessException('Nominierungen gibt es für Projekt, Technisches Design und Grundwerte.', 422);
            }
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            if ($counts[$category] > 4) {
                throw new ScorAccessException('Höchstens vier Nominierungen je Kategorie.', 422);
            }
        }

        $nominated = [];
        foreach ($nominations as $row) {
            $nominated[(string) $row['category']][(int) $row['team']] = true;
        }
        foreach ($trophies as $row) {
            $team = (int) $row['team'];
            $category = (string) $row['category'];
            if (in_array($category, self::NOMINATED, true) && empty($nominated[$category][$team])) {
                throw new ScorAccessException('Der Pokal geht nur an ein nominiertes Team.', 422);
            }
        }
    }

    /**
     * @return array{trophies: list<array{team: int, category: string}>, nominations: list<array{team: int, category: string}>, coaching: list<int>}
     */
    public function forEvent(int $eventId): array
    {
        $empty = ['trophies' => [], 'nominations' => [], 'coaching' => []];
        if (!Schema::hasTable('scor_award')) {
            return $empty;
        }

        $trophies = [];
        $nominations = [];
        $coaching = [];
        foreach (DB::table('scor_award')->where('event', $eventId)->orderBy('id')->get() as $row) {
            if ($row->kind === 'trophy' && $row->category === 'coaching') {
                $coaching[] = (int) $row->team;

                continue;
            }
            $entry = ['team' => (int) $row->team, 'category' => (string) $row->category];
            if ($row->kind === 'nomination') {
                $nominations[] = $entry;
            } else {
                $trophies[] = $entry;
            }
        }

        $coaching = array_values(array_unique($coaching));
        sort($coaching);

        return ['trophies' => $trophies, 'nominations' => $nominations, 'coaching' => $coaching];
    }

    /**
     * @param  list<array{team: int, category: string}>  $trophies
     * @param  list<array{team: int, category: string}>  $nominations
     * @param  list<int>  $coachingTeams
     */
    public function replace(int $eventId, array $trophies, array $nominations, array $coachingTeams): array
    {
        $coachingTeams = array_values(array_unique(array_map('intval', $coachingTeams)));
        sort($coachingTeams);
        $this->assertLegal($trophies, $nominations, $coachingTeams);
        if (!Schema::hasTable('scor_award')) {
            throw new ScorAccessException('Auszeichnungen sind noch nicht eingerichtet.', 503);
        }

        DB::transaction(function () use ($eventId, $trophies, $nominations, $coachingTeams) {
            DB::table('scor_award')->where('event', $eventId)->delete();
            $rows = [];
            foreach ($trophies as $row) {
                $rows[] = [
                    'event' => $eventId,
                    'team' => (int) $row['team'],
                    'category' => (string) $row['category'],
                    'kind' => 'trophy',
                ];
            }
            foreach ($nominations as $row) {
                $rows[] = [
                    'event' => $eventId,
                    'team' => (int) $row['team'],
                    'category' => (string) $row['category'],
                    'kind' => 'nomination',
                ];
            }
            foreach ($coachingTeams as $team) {
                $rows[] = [
                    'event' => $eventId,
                    'team' => $team,
                    'category' => 'coaching',
                    'kind' => 'trophy',
                ];
            }
            if ($rows !== []) {
                DB::table('scor_award')->insert($rows);
            }
        });

        return $this->forEvent($eventId);
    }
}
