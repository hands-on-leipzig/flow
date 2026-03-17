<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtraBlock extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'extra_block';

    protected $fillable = [
        'plan',
        'first_program',
        'name',
        'description',
        'link',
        'insert_point',
        'buffer_before',
        'duration',
        'buffer_after',
        'start',
        'end',
        'room',
        'active',
        'type',
    ];

    protected $casts = [
        'buffer_before' => 'integer',
        'duration' => 'integer',
        'buffer_after' => 'integer',
        'start' => 'datetime',
        'end' => 'datetime',
        'active' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    public function insertPoint()
    {
        return $this->belongsTo(MInsertPoint::class, 'insert_point');
    }

    /**
     * Assigned physical room (FK column is also named `room`).
     * Do not name this relationship `room()` — it shadows the FK attribute and breaks BelongsTo.
     */
    public function assignedRoom()
    {
        return $this->belongsTo(Room::class, 'room');
    }

    /**
     * Team start-time assignments for slot blocks (type = slot).
     */
    public function slotBlockTeams(): HasMany
    {
        return $this->hasMany(SlotBlockTeam::class, 'extra_block');
    }
}
