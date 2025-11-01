<?php

namespace App\Support;

use App\Models\MSupportedPlan;
use RuntimeException;

class SupportedPlanChecker
{
    /**
     * Prüft, ob eine bestimmte Kombination in m_supported_plan existiert.
     * 
     * Wenn nicht, wird automatisch eine RuntimeException geworfen.
     *
     * @param int $firstProgram  First Program ID (3=Challenge, 2=Explore) from m_first_program table
     * @param int $teams         Anzahl Teams
     * @param int $lanes         Anzahl Lanes (Judging oder Tische)
     * @param int|null $tables   Optional: Anzahl Robot-Game-Tables
     * @throws RuntimeException  Wenn keine passende Kombination existiert
     */
    public static function exists(int $firstProgram, int $teams, int $lanes, ?int $tables = null): bool
    {
        $query = MSupportedPlan::query()
            ->where('first_program', $firstProgram)
            ->where('teams', $teams)
            ->where('lanes', $lanes);

        if ($tables === null) {
            $query->whereNull('tables');
        } else {
            $query->where('tables', $tables);
        }

        $exists = $query->exists();

        if (!$exists) {
            $parts = [$firstProgram, $teams, $lanes];
            if ($tables !== null) {
                $parts[] = $tables;
            }
            $desc = implode('-', $parts);
            $programName = $firstProgram === 3 ? 'Challenge' : ($firstProgram === 2 ? 'Explore' : "Programm {$firstProgram}");
            $tablesInfo = $tables !== null ? ", Tische: {$tables}" : "";
            throw new RuntimeException("Nicht unterstützte Plan-Konfiguration für {$programName}: Teams: {$teams}, Spuren: {$lanes}{$tablesInfo}. Diese Kombination existiert nicht in m_supported_plan.");
        }

        return true;
    }
}