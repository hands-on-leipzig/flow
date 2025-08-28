<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QRun extends Model
{
    // Database table
    protected $table = 'q_run';

    // Allow timestamps if needed later; currently set to false
    public $timestamps = false;

    // Mass assignable fields
    protected $fillable = [
        'name',
        'selection',
        'started_at',
        'finished_at',
        'status',
        'comment',
    ];

    /**
     * Returns all quality plans that belong to this test run
     */
    public function qPlans()
    {
        return $this->hasMany(QPlan::class, 'q_run');
    }
}