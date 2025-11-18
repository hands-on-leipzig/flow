<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    public $timestamps = false; // We use last_change instead of created_at/updated_at

    protected $fillable = [
        'event',
        'level',
        'last_change'
    ];

    /**
     * Get the event that owns the publication.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    /**
     * Scope to get latest publication for an event
     */
    public function scopeLatestForEvent($query, int $eventId)
    {
        return $query->where('event', $eventId)
            ->orderBy('last_change', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Scope to get all publications for an event (ordered)
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event', $eventId)
            ->orderBy('last_change', 'asc')
            ->orderBy('id', 'asc');
    }
}
