<?php

namespace App\Support;

/**
 * Public-plan picker slice: keep rows that match the chosen lane, table, or team.
 * A null on that axis is shared and stays in the slice.
 */
final class RoleScheduleSlice
{
    /**
     * @param  callable(object, int): bool  $activityMatchesTeam
     */
    public static function matches(
        object $row,
        ?int $lane,
        ?int $table,
        ?int $team,
        callable $activityMatchesTeam,
    ): bool {
        if ($lane !== null) {
            if ($row->lane !== null && (int) $row->lane !== $lane) {
                return false;
            }
        }

        if ($table !== null) {
            $t1 = $row->table_1 !== null ? (int) $row->table_1 : null;
            $t2 = $row->table_2 !== null ? (int) $row->table_2 : null;
            if ($t1 !== null || $t2 !== null) {
                if ($t1 !== $table && $t2 !== $table) {
                    return false;
                }
            }
        }

        if ($team !== null && ! $activityMatchesTeam($row, $team)) {
            return false;
        }

        return true;
    }
}
