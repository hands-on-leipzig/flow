<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QPlanMatch extends Model
{
    // Database table
    protected $table = 'q_plan_match';

    // No timestamps
    public $timestamps = false;

    // Mass assignable fields
    protected $fillable = [
        'q_plan',
        'round',
        'match_no',
        'table_1',
        'table_2',
        'table_1_team',
        'table_2_team'
    ];

    // Type casting for proper data types
    protected $casts = [
        'q_plan' => 'integer',
        'round' => 'integer',
        'match_no' => 'integer',
        'table_1' => 'integer',
        'table_2' => 'integer',
        'table_1_team' => 'integer',
        'table_2_team' => 'integer',
    ];

    /**
     * Returns the parent quality plan this match entry belongs to
     */
    public function qPlan()
    {
        return $this->belongsTo(QPlan::class, 'q_plan');
    }
}