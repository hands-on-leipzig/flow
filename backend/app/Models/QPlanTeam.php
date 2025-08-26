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
        'q2_ok',
        'q3_ok',
        'q4_ok',
        'q5_gap_0_1',
        'q5_gap_1_2',
        'q5_gap_2_3'
    ];

    /**
     * Returns the parent quality plan this team entry belongs to
     */
    public function qPlan()
    {
        return $this->belongsTo(QPlan::class, 'q_plan');
    }
}