<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventStaffingRole extends Model
{
    protected $table = 'event_staffing_role';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'm_role',
        'label',
        'min',
        'best',
        'max',
        'ui_description',
        'sequence',
    ];

    protected $casts = [
        'min' => 'integer',
        'best' => 'integer',
        'max' => 'integer',
        'sequence' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function catalogRole(): BelongsTo
    {
        return $this->belongsTo(MRole::class, 'm_role');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(EventStaffingGroup::class, 'event_staffing_role');
    }

    public function isLocal(): bool
    {
        return $this->m_role === null;
    }

    public function isCatalog(): bool
    {
        return $this->m_role !== null;
    }
}
