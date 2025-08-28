<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QPlan extends Model
{
    // Database table
    protected $table = 'q_plan';

    // No timestamps (created_at, updated_at)
    public $timestamps = false;

    // Mass assignable fields
    protected $fillable = [
        'plan',
        'q_run',
        'c_teams',
        'r_tables',
        'j_lanes',
        'j_rounds',
        'r_robot_check',
        'r_duration_robot_check',
        'q1_ok_count',
        'q2_ok_count',
        'q3_ok_count',
        'q4_ok_count',
        'q5_idle_avg',
        'q5_idle_stddev',
        'calculated',
    ];

    /**
     * Returns the related plan (technical plan data)
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    /**
     * Returns the run that triggered this quality evaluation
     */
    public function run()
    {
        return $this->belongsTo(QRun::class, 'q_run');
    }

    /**
     * Returns all Q entries per team (optional, use if needed)
     */
    public function qTeams()
    {
        return $this->hasMany(QPlanTeam::class, 'q_plan');
    }

    /**
     * Returns all matches with team/table layout (for UI rendering)
     */
    public function matches()
    {
        return $this->hasMany(QPlanMatch::class, 'q_plan');
    }
}