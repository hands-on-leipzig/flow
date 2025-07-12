<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MParameter extends Model
{
    protected $table = 'm_parameter';
    public $timestamps = false;

    public function valueForPlan($planId)
    {
        return $this->hasOne(PlanParamValue::class, 'parameter')
            ->where('plan', $planId);
    }

}

