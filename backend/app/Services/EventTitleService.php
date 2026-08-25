<?php

namespace App\Services;

use App\Support\ProgramCatalog;

class EventTitleService
{
    /**
     * Get long format event title
     * Returns: "FIRST LEGO League Ausstellung und Regionalwettbewerb Aachen"
     * 
     * @param object $event Event with programs, level, and name
     * @return string
     */
    public function getEventTitleLong(object $event): string
    {
        $competitionType = $this->getCompetitionTypeText($event);
        $eventName = $this->cleanEventName($event);
        
        return trim('FIRST LEGO League ' . $competitionType . ' ' . $eventName);
    }

    /**
     * Get short format event title
     * Returns: "Ausstellung und Regio Aachen"
     * 
     * @param object $event Event with programs, level, and name
     * @return string
     */
    public function getEventTitleShort(object $event): string
    {
        $competitionType = $this->getCompetitionTypeText($event);
        $abbreviatedType = $this->abbreviateCompetitionType($competitionType);
        $eventName = $this->cleanEventName($event);
        
        return trim($abbreviatedType . ' ' . $eventName);
    }

    /**
     * Get competition type text only (for "Art:" display)
     * Returns: "Ausstellung und Regionalwettbewerb", "Ausstellung", "Regionalwettbewerb", etc.
     * 
     * @param object $event Event with programs and level
     * @return string
     */
    public function getCompetitionTypeText(object $event): string
    {
        $flags = $this->programFlags($event);
        $hasExplore = $flags['explore'];
        $hasChallenge = $flags['challenge'];
        $hasFuture = $flags['future'];
        $level = (int)($event->level ?? 0);

        // First check level - level 2 and 3 take precedence regardless of E/C
        if ($level === 2) {
            return 'Qualifikationswettbewerb';
        }

        if ($level === 3) {
            return 'Finale';
        }

        if ($hasFuture && !$hasExplore && !$hasChallenge) {
            return 'Future Wettbewerb';
        }
        if ($hasFuture && ($hasExplore || $hasChallenge)) {
            return 'Mixed Wettbewerb';
        }

        // For level 1, check E/C combinations
        if ($level === 1) {
            if ($hasExplore && $hasChallenge) {
                return 'Ausstellung und Regionalwettbewerb';
            }
            if ($hasExplore && !$hasChallenge) {
                return 'Ausstellung';
            }
            if ($hasChallenge && !$hasExplore) {
                return 'Regionalwettbewerb';
            }
        }

        // Fallback
        return 'Wettbewerb';
    }

    /**
     * @return array{explore: bool, challenge: bool, future: bool}
     */
    private function programFlags(object $event): array
    {
        if ($event instanceof \App\Models\Event) {
            return [
                'explore' => ProgramCatalog::hasExplore($event),
                'challenge' => ProgramCatalog::hasChallenge($event),
                'future' => ProgramCatalog::hasFuture($event),
            ];
        }

        $programs = collect($event->programs ?? []);
        $names = $programs->map(function ($row) {
            if (is_array($row)) {
                return strtoupper((string) ($row['name'] ?? ''));
            }

            return strtoupper((string) ($row->name ?? ''));
        });

        return [
            'explore' => $names->contains(ProgramCatalog::EXPLORE),
            'challenge' => $names->contains(ProgramCatalog::CHALLENGE),
            'future' => $names->contains(fn ($name) => ProgramCatalog::isFuture($name)),
        ];
    }

    /**
     * Abbreviate competition type for short format
     * 
     * @param string $competitionType Full competition type text
     * @return string Abbreviated version
     */
    private function abbreviateCompetitionType(string $competitionType): string
    {
        // Replace "Regionalwettbewerb" with "Regio"
        $abbreviated = str_replace('Regionalwettbewerb', 'Regio', $competitionType);
        
        // Replace "Qualifikationswettbewerb" with "Quali"
        $abbreviated = str_replace('Qualifikationswettbewerb', 'Quali', $abbreviated);
        
        return $abbreviated;
    }

    /**
     * Clean event name by removing redundant prefixes based on level
     * 
     * @param object $event Event object with level and name properties
     * @return string Cleaned event name
     */
    public function cleanEventName(object $event): string
    {
        $eventName = $event->name ?? '';
        $level = (int)($event->level ?? 0);

        // Remove "Qualifikation " prefix if level is 2
        if ($level === 2) {
            $eventName = preg_replace('/^Qualifikation\s+/i', '', $eventName);
        }

        // Remove "Finale " prefix if level is 3
        if ($level === 3) {
            $eventName = preg_replace('/^Finale\s+/i', '', $eventName);
        }

        return trim($eventName);
    }
}
