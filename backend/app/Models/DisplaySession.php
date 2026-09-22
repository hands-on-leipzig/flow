<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplaySession extends Model
{
    protected $table = 's_display_session';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'device_id',
        'kind',
        'start',
        'last_seen',
        'user_agent',
        'ip_hash',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
