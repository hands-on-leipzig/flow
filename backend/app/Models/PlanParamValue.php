<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanParamValue extends Model
{
    protected $table = 'plan_param_value';
    public $timestamps = false;

    protected $fillable = [
        'parameter',
        'plan',
        'set_value',
    ];

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(MParameter::class, 'parameter');
    }

    protected $appends = ['value'];

    public function getValueAttribute()
    {
        return $this->set_value;
    }
}

