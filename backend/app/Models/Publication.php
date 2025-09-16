<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    protected $fillable = [
        'event',
        'level'
    ];

    /**
     * Get the event that owns the publication.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
