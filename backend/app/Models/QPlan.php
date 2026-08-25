<?php

namespace App\Models;

use App\Enums\FirstProgram;
use App\Support\ChallengeShapedParamMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generated plan inside a quality mass-test run.
 *
 * first_program is the program under test (Challenge or Future 8+).
 * Columns c_teams / j_lanes / r_tables store that program's grid dimensions
 * (teams / lanes / tables-or-fields); live plan_param_value names come from
 * ChallengeShapedParamMap.
 */
class QPlan extends Model
{
    protected $table = 'q_plan';

    public $timestamps = false;

    protected $fillable = [
        'plan',
        'q_run',
        'first_program',
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

    protected $casts = [
        'plan' => 'integer',
        'q_run' => 'integer',
        'first_program' => 'integer',
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

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    public function run()
    {
        return $this->belongsTo(QRun::class, 'q_run');
    }

    public function qTeams()
    {
        return $this->hasMany(QPlanTeam::class, 'q_plan');
    }

    /**
     * All match rows for the linked plan (may include multiple programs).
     * Prefer matchesForProgram() when evaluating this q_plan.
     */
    public function matches(): HasMany
    {
        return $this->hasMany(MatchEntry::class, 'plan', 'plan');
    }

    /**
     * Match rows scoped to this q_plan's first_program.
     */
    public function matchesForProgram(): HasMany
    {
        return $this->matches()->where('match.first_program', $this->first_program);
    }

    public function program(): FirstProgram
    {
        return FirstProgram::from((int) $this->first_program);
    }

    public function paramMap(): ChallengeShapedParamMap
    {
        return ChallengeShapedParamMap::from((int) $this->first_program);
    }
}