<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventStaffingAssignment extends Model
{
    protected $table = 'event_staffing_assignment';

    public $timestamps = false;

    protected $fillable = [
        'event_staffing_role',
        'event_staffing_group',
        'volunteer_person',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(EventStaffingRole::class, 'event_staffing_role');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EventStaffingGroup::class, 'event_staffing_group');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(VolunteerPerson::class, 'volunteer_person');
    }
}
