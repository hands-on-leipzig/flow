<?php

namespace App\Services;

class EventTitleService
{
    /**
     * Get long format event title
     * Returns: "FIRST LEGO League Ausstellung und Regionalwettbewerb Aachen"
     * 
     * @param object $event Event object with event_explore, event_challenge, level, and name properties
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
     * @param object $event Event object with event_explore, event_challenge, level, and name properties
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
     * @param object $event Event object with event_explore, event_challenge, and level properties
     * @return string
     */
    public function getCompetitionTypeText(object $event): string
    {
        $hasExplore = !empty($event->event_explore);
        $hasChallenge = !empty($event->event_challenge);
        $level = (int)($event->level ?? 0);

        // Both Explore and Challenge Regio (level 1)
        if ($hasExplore && $hasChallenge && $level === 1) {
            return 'Ausstellung und Regionalwettbewerb';
        }

        // Only Explore
        if ($hasExplore && !$hasChallenge) {
            return 'Ausstellung';
        }

        // Only Challenge - check level
        if ($hasChallenge && !$hasExplore) {
            return match ($level) {
                1 => 'Regionalwettbewerb',
                2 => 'Qualifikationswettbewerb',
                3 => 'Finale',
                default => 'Wettbewerb',
            };
        }

        // Fallback
        return 'Wettbewerb';
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
