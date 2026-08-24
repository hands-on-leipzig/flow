<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCalendar extends Model
{
    public $timestamps = false;

    protected $table = 'event_calendar';

    protected $fillable = [
        'event',
        'date',
        'uid',
        'sequence',
        'vevent',
        'built_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sequence' => 'integer',
            'built_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
