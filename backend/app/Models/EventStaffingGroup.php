<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventStaffingGroup extends Model
{
    protected $table = 'event_staffing_group';

    public $timestamps = false;

    protected $fillable = [
        'event_staffing_role',
        'group_index',
        'surplus',
    ];

    protected $casts = [
        'group_index' => 'integer',
        'surplus' => 'boolean',
    ];

    public function staffingRole(): BelongsTo
    {
        return $this->belongsTo(EventStaffingRole::class, 'event_staffing_role');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EventStaffingAssignment::class, 'event_staffing_group');
    }
}
