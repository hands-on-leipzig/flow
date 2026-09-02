<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventVolunteerField extends Model
{
    protected $table = 'event_volunteer_field';

    protected $fillable = [
        'event',
        'field_key',
        'label',
        'type',
        'options',
        'sequence',
        'public_form',
    ];

    protected $casts = [
        'options' => 'array',
        'sequence' => 'integer',
        'public_form' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function values(): HasMany
    {
        return $this->hasMany(EventVolunteerFieldValue::class, 'event_volunteer_field');
    }
}
