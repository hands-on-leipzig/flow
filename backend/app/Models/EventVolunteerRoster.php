<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVolunteerRoster extends Model
{
    protected $table = 'event_volunteer_roster';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'volunteer_person',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(VolunteerPerson::class, 'volunteer_person');
    }

    public function detail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EventVolunteerRosterDetail::class, 'event_volunteer_roster');
    }
}
