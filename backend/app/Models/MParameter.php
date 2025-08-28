<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MParameter extends Model
{
    protected $table = 'm_parameter';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'context',
        'level',
        'type',
        'value',
        'min',
        'max',
        'step',
        'first_program',
        'sequence',
        'ui_label',
        'ui_description',
    ];

    public function planParamValues()
    {
        return $this->hasMany(PlanParamValue::class, 'parameter');
    }

    public function firstProgram()
    {
        return $this->belongsTo(FirstProgram::class, 'first_program');
    }

}
