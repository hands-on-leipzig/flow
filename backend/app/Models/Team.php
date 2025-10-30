<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'team';

    protected $fillable = [
        "id",
        "first_program",
        "name",
        "event",
        "team_number_hot",
        "location",
        "organization"
    ];

    public $timestamps = false;

    public function event()
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function teamPlans()
    {
        return $this->hasMany(TeamPlan::class, 'team');
    }
}
