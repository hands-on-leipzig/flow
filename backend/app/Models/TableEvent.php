<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableEvent extends Model
{
    protected $table = 'table_event';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'table_number',
        'table_name',
    ];

    // Beziehung zurück zum Event (optional)
    public function eventRel()
    {
        return $this->belongsTo(Event::class, 'event');
    }
}