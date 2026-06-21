<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSlug extends Model
{
    protected $table = 'event_slug';

    protected $fillable = [
        'slug',
        'event_id',
        'season_id',
        'program',
        'variant',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function season()
    {
        return $this->belongsTo(MSeason::class, 'season_id');
    }
}
