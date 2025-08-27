<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QPlanTeam extends Model
{
    // Database table
    protected $table = 'q_plan_team';

    // No timestamps
    public $timestamps = false;

    // Mass assignable fields
    protected $fillable = [
        'q_plan',
        'team',
        'q1_ok',
        'q1_transition_1_2',
        'q1_transition_2_3',
        'q1_transition_3_4',
        'q1_transition_4_5',
        'q2_ok',
        'q2_tables',
        'q3_ok',
        'q3_teams',
        'q4_ok',
        'q5_idle_0_1',
        'q5_idle_1_2',
        'q5_idle_2_3',
        'q5_idle_avg',
    ];

    /**
     * Returns the parent quality plan this team entry belongs to
     */
    public function qPlan()
    {
        return $this->belongsTo(QPlan::class, 'q_plan');
    }
}