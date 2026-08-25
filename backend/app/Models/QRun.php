<?php

namespace App\Models;

use App\Enums\FirstProgram;
use Illuminate\Database\Eloquent\Model;

/**
 * One quality mass-test run. first_program is Challenge or Future 8+ (not mixed).
 */
class QRun extends Model
{
    protected $table = 'q_run';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'first_program',
        'host',
        'selection',
        'started_at',
        'finished_at',
        'status',
        'comment',
        'qplans_total',
        'qplans_calculated',
    ];

    protected $casts = [
        'first_program' => 'integer',
        'qplans_total' => 'integer',
        'qplans_calculated' => 'integer',
    ];

    public function qPlans()
    {
        return $this->hasMany(QPlan::class, 'q_run');
    }

    public function program(): ?FirstProgram
    {
        if ($this->first_program === null) {
            return null;
        }

        return FirstProgram::from((int) $this->first_program);
    }
}