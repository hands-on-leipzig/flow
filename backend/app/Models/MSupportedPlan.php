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
}
