<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotBlockTeam extends Model
{
    public $timestamps = false;

    protected $table = 'slot_block_team';

    protected $fillable = [
        'extra_block',
        'team_number_plan',
        'first_program',
        'start',
    ];

    protected $casts = [
        'start' => 'datetime',
    ];

    public function extraBlock(): BelongsTo
    {
        return $this->belongsTo(ExtraBlock::class, 'extra_block');
    }

}
