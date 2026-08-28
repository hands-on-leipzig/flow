<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVolunteerFieldValue extends Model
{
    public $timestamps = false;

    protected $table = 'event_volunteer_field_value';

    protected $fillable = [
        'event_volunteer_roster',
        'event_volunteer_field',
        'value',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function roster(): BelongsTo
    {
        return $this->belongsTo(EventVolunteerRoster::class, 'event_volunteer_roster');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(EventVolunteerField::class, 'event_volunteer_field');
    }
}
