<?php

namespace App\Helpers;

class PdfHelper
{
    /**
     * Format team name with strikethrough if it's a no-show team.
     * 
     * @param string|null $teamName The team name to format (can include HOT number)
     * @param bool $isNoshow Whether the team is a no-show
     * @return string HTML formatted team name with strikethrough if no-show
     */
    public static function formatTeamNameWithNoshow(?string $teamName, bool $isNoshow = false): string
    {
        if (empty($teamName)) {
            return '–';
        }

        if ($isNoshow) {
            return '<span style="text-decoration: line-through;">' . e($teamName) . '</span>';
        }

        return e($teamName);
    }
}
