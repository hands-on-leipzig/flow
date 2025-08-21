<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'buffer_before' => 'integer',
        'duration' => 'integer',
        'buffer_after' => 'integer',
        'start' => 'datetime',
        'end' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    public function insertPoint()
    {
        return $this->belongsTo(MInsertPoint::class, 'insert_point');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room');
    }
}
