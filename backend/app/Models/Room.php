<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $table = 'room';

    protected $fillable = [
        "id",
        "event",
        "name",
        "navigation_instruction"

    ];

    public $timestamps = false;

    public function event()
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function roomTypes()
    {
        return $this->belongsToMany(MRoomType::class, 'room_type_room', 'room', 'room_type');
    }
}
