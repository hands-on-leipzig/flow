<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plan';
    public $timestamps = false;
    protected $fillable = [
        'name',
        'event',
        'created',
        'last_change',
        'public'
    ];

    public function parameters()
    {
        return $this->hasMany(PlanParamValue::class, 'plan');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}

