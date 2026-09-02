<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTeamMealCount extends Model
{
    protected $table = 'event_team_meal_count';

    public $timestamps = false;

    protected $fillable = [
        'team',
        'meal_value',
        'count',
        'updated_at',
    ];

    protected $casts = [
        'count' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team');
    }
}
