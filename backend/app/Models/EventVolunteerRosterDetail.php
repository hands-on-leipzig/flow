<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVolunteerRosterDetail extends Model
{
    public $timestamps = false;

    protected $table = 'event_volunteer_roster_detail';

    protected $fillable = [
        'event_volunteer_roster',
        't_shirt_cut',
        't_shirt_size',
        'meal',
        'photo_consent',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function roster(): BelongsTo
    {
        return $this->belongsTo(EventVolunteerRoster::class, 'event_volunteer_roster');
    }
}
