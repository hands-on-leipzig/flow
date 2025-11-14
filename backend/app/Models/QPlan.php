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
        'name',
        'last_change',
        'c_teams',
        'r_tables',
        'j_lanes',
        'j_rounds',
        'r_asym',
        'r_robot_check',
        'r_duration_robot_check',
        'c_duration_transfer',
        'q1_ok_count',
        'q2_ok_count',
        'q2_1_count',
        'q2_2_count',
        'q2_3_count',
        'q2_score_avg',
        'q3_ok_count',
        'q3_1_count',
        'q3_2_count',
        'q3_3_count',
        'q3_score_avg',
        'q4_ok_count',
        'q5_idle_avg',
        'q5_idle_stddev',
        'q6_duration',
        'calculated',
    ];

    // Type casting for proper data types
    protected $casts = [
        'plan' => 'integer',
        'q_run' => 'integer',
        'last_change' => 'datetime',
        'c_teams' => 'integer',
        'r_tables' => 'integer',
        'j_lanes' => 'integer',
        'j_rounds' => 'integer',
        'r_asym' => 'boolean',
        'r_robot_check' => 'boolean',
        'r_duration_robot_check' => 'integer',
        'c_duration_transfer' => 'integer',
        'q1_ok_count' => 'integer',
        'q2_ok_count' => 'integer',
        'q2_1_count' => 'integer',
        'q2_2_count' => 'integer',
        'q2_3_count' => 'integer',
        'q2_score_avg' => 'float',
        'q3_ok_count' => 'integer',
        'q3_1_count' => 'integer',
        'q3_2_count' => 'integer',
        'q3_3_count' => 'integer',
        'q3_score_avg' => 'float',
        'q4_ok_count' => 'integer',
        'q5_idle_avg' => 'float',
        'q5_idle_stddev' => 'float',
        'calculated' => 'boolean',
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
        return $this->hasMany(\App\Models\MatchEntry::class, 'plan', 'plan');
    }
}