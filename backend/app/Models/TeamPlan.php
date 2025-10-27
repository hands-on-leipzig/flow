<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamPlan extends Model
{
    protected $table = 'team_plan';
    public $timestamps = false;

    protected $fillable = [
        'team',
        'plan',
        'team_number_plan',
        'room',
        'noshow'
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'team');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }
}
