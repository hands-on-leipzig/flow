<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VolunteerPerson extends Model
{
    protected $table = 'volunteer_person';

    protected $fillable = [
        'regional_partner',
        'draht_id',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'organization',
    ];

    protected $casts = [
        'draht_id' => 'integer',
    ];

    public function hasAccount(): bool
    {
        return (int) $this->draht_id > 0;
    }

    public function regionalPartner(): BelongsTo
    {
        return $this->belongsTo(RegionalPartner::class, 'regional_partner');
    }

    public function rosterEntries(): HasMany
    {
        return $this->hasMany(EventVolunteerRoster::class, 'volunteer_person');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EventStaffingAssignment::class, 'volunteer_person');
    }
}
