<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirstProgram extends Model
{
    protected $table = 'm_first_program';

    protected $fillable = [
        'id',
        'name',
        'display_name',
        'letter',
        'ics_postfix',
        'sequence',
        'max_match_rounds',
        'color_hex',
        'logo_stem',
        'logo_white',
    ];

    public $timestamps = false;
}
