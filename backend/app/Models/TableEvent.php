<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableEvent extends Model
{
    protected $table = 'table_event';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'first_program',
        'table_number',
        'table_name',
    ];

    public function eventRel(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function firstProgramRel(): BelongsTo
    {
        return $this->belongsTo(FirstProgram::class, 'first_program');
    }
}
