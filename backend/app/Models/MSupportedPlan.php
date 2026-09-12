<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MSupportedPlan extends Model
{
    protected $table = 'm_supported_plan';
    public $timestamps = false;

    protected $fillable = [
        'first_program',
        'teams',
        'lanes',
        'tables',
        'calibration',
        'note',
        'alert_level',
    ];

    /**
     * Best grid for a program + team count: prefer alert_level = 1 (recommended),
     * otherwise the first available row for that teams count.
     */
    public static function bestFor(int $firstProgram, int $teams): ?self
    {
        $best = static::query()
            ->where('first_program', $firstProgram)
            ->where('teams', $teams)
            ->where('alert_level', 1)
            ->orderBy('id')
            ->first();

        if ($best !== null) {
            return $best;
        }

        return static::query()
            ->where('first_program', $firstProgram)
            ->where('teams', $teams)
            ->orderBy('id')
            ->first();
    }

    /**
     * Team and lane extents in m_supported_plan for one first_program.
     *
     * @return array{min_teams: int, max_teams: int, min_lanes: int, max_lanes: int}|null
     */
    public static function selectionBounds(int $firstProgram): ?array
    {
        $row = static::query()
            ->where('first_program', $firstProgram)
            ->selectRaw('MIN(teams) as min_teams, MAX(teams) as max_teams, MIN(lanes) as min_lanes, MAX(lanes) as max_lanes')
            ->first();

        if ($row === null || $row->min_teams === null || $row->min_lanes === null) {
            return null;
        }

        return [
            'min_teams' => (int) $row->min_teams,
            'max_teams' => (int) $row->max_teams,
            'min_lanes' => (int) $row->min_lanes,
            'max_lanes' => (int) $row->max_lanes,
        ];
    }
}
