<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MRoomType extends Model
{
    protected $table = 'm_room_type';

    public function group()
    {
        return $this->belongsTo(MRoomTypeGroup::class, 'room_type_group');
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'room_type_room', 'room_type', 'room')
            ->withPivot('sequence')
            ->orderBy('room_type_room.sequence');
    }
}
