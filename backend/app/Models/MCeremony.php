<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MCeremony extends Model
{
    protected $table = 'm_ceremonies';

    public $timestamps = false;

    public const KIND_OPENING = 'opening';

    public const KIND_AWARDS = 'awards';

    protected $fillable = [
        'activity_type_detail',
        'kind',
        'start_parameter',
        'duration_parameter',
    ];

    public function activityTypeDetail(): BelongsTo
    {
        return $this->belongsTo(MActivityTypeDetail::class, 'activity_type_detail');
    }

    public function startParameter(): BelongsTo
    {
        return $this->belongsTo(MParameter::class, 'start_parameter');
    }

    public function durationParameter(): BelongsTo
    {
        return $this->belongsTo(MParameter::class, 'duration_parameter');
    }
}
