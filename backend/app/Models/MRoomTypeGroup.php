<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MRoomTypeGroup extends Model
{
    protected $table = 'm_room_type_group';

    public function types()
    {
        return $this->hasMany(MRoomType::class, 'group');
    }

    public function program()
    {
        return $this->belongsTo(FirstProgram::class, 'first_program'); // or similar
    }
}
