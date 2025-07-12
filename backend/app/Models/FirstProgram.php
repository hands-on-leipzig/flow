<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirstProgram extends Model
{
    protected $table = 'm_first_program';

    protected $fillable = [
        'id',
        'name',
        'sequence',
        'color_hex',
        'logo_white'

    ];

    public $timestamps = false;
}
