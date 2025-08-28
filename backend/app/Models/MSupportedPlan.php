<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MSupportedPlan extends Model
{
    protected $table = 'm_supported_plan';
    public $timestamps = false;

    protected $fillable = [
        'first_program',
        'teams',
        'lanes',
        'tables',
        'calibration',
        'note',
    ];
}