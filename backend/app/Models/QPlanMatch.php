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

    /**
     * Returns the parent quality plan this match entry belongs to
     */
    public function qPlan()
    {
        return $this->belongsTo(QPlan::class, 'q_plan');
    }
}