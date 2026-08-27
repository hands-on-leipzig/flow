<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MStaffingRule extends Model
{
    protected $table = 'm_staffing_rule';

    protected $primaryKey = 'm_role';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'm_role',
        'min',
        'best',
        'max',
        'ui_description',
    ];

    protected $casts = [
        'min' => 'integer',
        'best' => 'integer',
        'max' => 'integer',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(MRole::class, 'm_role');
    }
}
