<?php

namespace App\Scor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One trophy among Champion, Forschung, Roboterdesign and Grundwerte.
 * Spiel and Coaching may be added. A team holds at most three trophies.
 * Each judged category has at most four nominations.
 */
final class ScorAwards
{
    /** @var list<string> */
    public const JUDGED = ['champion', 'research', 'robotdesign', 'core_values'];

    /** @var list<string> */
    public const NOMINATED = ['research', 'robotdesign', 'core_values'];

    /**
     * @param  list<array{team: int, category: string}>  $trophies
     * @param  list<array{team: int, category: string}>  $nominations
     */
    public function assertLegal(array $trophies, array $nominations, ?int $coachingTeam): void
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
        if ($coachingTeam) {
            $perTeam[$coachingTeam][] = 'coaching';
        }

        foreach ($perTeam as $categories) {
            if (count($categories) !== count(array_unique($categories))) {
                throw new ScorAccessException('Dieselbe Auszeichnung steht bei einem Team nur einmal.', 422);
            }
            $judged = array_values(array_intersect($categories, self::JUDGED));
            if (count($judged) > 1) {
                throw new ScorAccessException('Ein Team bekommt nur einen Pokal aus Champion, Forschung, Roboterdesign und Grundwerten.', 422);
            }
            if (count($categories) > 3) {
                throw new ScorAccessException('Ein Team bekommt höchstens drei Pokale.', 422);
            }
        }

        $counts = [];
        foreach ($nominations as $row) {
            $category = (string) $row['category'];
            if (!in_array($category, self::NOMINATED, true)) {
                throw new ScorAccessException('Nominierungen gibt es für Forschung, Roboterdesign und Grundwerte.', 422);
            }
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            if ($counts[$category] > 4) {
                throw new ScorAccessException('Höchstens vier Nominierungen je Kategorie.', 422);
            }
        }
    }

    /**
     * @return array{trophies: list<array{team: int, category: string}>, nominations: list<array{team: int, category: string}>, coaching: ?int}
     */
    public function forEvent(int $eventId): array
    {
        $empty = ['trophies' => [], 'nominations' => [], 'coaching' => null];
        if (!Schema::hasTable('scor_award')) {
            return $empty;
        }

        $trophies = [];
        $nominations = [];
        $coaching = null;
        foreach (DB::table('scor_award')->where('event', $eventId)->orderBy('id')->get() as $row) {
            if ($row->kind === 'trophy' && $row->category === 'coaching') {
                $coaching = (int) $row->team;

                continue;
            }
            $entry = ['team' => (int) $row->team, 'category' => (string) $row->category];
            if ($row->kind === 'nomination') {
                $nominations[] = $entry;
            } else {
                $trophies[] = $entry;
            }
        }

        return ['trophies' => $trophies, 'nominations' => $nominations, 'coaching' => $coaching];
    }

    /**
     * @param  list<array{team: int, category: string}>  $trophies
     * @param  list<array{team: int, category: string}>  $nominations
     */
    public function replace(int $eventId, array $trophies, array $nominations, ?int $coachingTeam): array
    {
        $this->assertLegal($trophies, $nominations, $coachingTeam);
        if (!Schema::hasTable('scor_award')) {
            throw new ScorAccessException('Auszeichnungen sind noch nicht eingerichtet.', 503);
        }

        DB::transaction(function () use ($eventId, $trophies, $nominations, $coachingTeam) {
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
            if ($coachingTeam) {
                $rows[] = [
                    'event' => $eventId,
                    'team' => $coachingTeam,
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
