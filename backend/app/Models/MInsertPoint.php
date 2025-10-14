<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MInsertPoint extends Model
{
    protected $table = 'm_insert_point';
    
    protected $fillable = [
        'code',
        'first_program',
        'level',
        'sequence',
        'ui_label',
        'ui_description',
    ];
}
