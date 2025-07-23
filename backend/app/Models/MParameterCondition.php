<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MParameterCondition extends Model
{
    protected $table = 'm_parameter_condition';
    public $timestamps = false;

    protected $fillable = [
        'parameter',
        'if_parameter',
        'is',
        'value',
        'action',
    ];
}
