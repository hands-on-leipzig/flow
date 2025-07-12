<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plan';
    public $timestamps = false;

    public function parameters()
    {
        return $this->hasMany(PlanParamValue::class, 'plan');
    }
}

