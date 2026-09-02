<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTeamPhotoCount extends Model
{
    public $timestamps = false;

    protected $table = 'event_team_photo_count';

    protected $fillable = [
        'team',
        'bucket',
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
