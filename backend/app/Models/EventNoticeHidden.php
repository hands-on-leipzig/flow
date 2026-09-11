<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventNoticeHidden extends Model
{
    protected $table = 'event_notice_hidden';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'notice',
    ];

    protected $casts = [
        'event' => 'integer',
        'notice' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(MNotice::class, 'notice');
    }
}
