<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTeamFieldValue extends Model
{
    protected $table = 'event_team_field_value';

    public $timestamps = false;

    protected $fillable = [
        'team',
        'event_team_field',
        'value',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(EventTeamField::class, 'event_team_field');
    }
}
