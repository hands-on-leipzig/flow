<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MInsertPoint extends Model
{
    protected $table = 'm_insert_point';
    
    protected $fillable = [
        'first_program',
        'level',
        'sequence',
        'ui_label',
        'ui_description',
        'room_type'
    ];
    
    public function roomType()
    {
        return $this->belongsTo(MRoomType::class, 'room_type');
    }
}
