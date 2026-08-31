<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    public const SUBJECT_TEAM = 'team';

    public const SUBJECT_VOLUNTEER = 'volunteer';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_NO_SHOW = 'no_show';

    protected $table = 'check_in';

    protected $fillable = [
        'event',
        'subject_type',
        'subject_id',
        'status',
        'checked_in_at',
        'reception_note',
        'no_show_reason',
        'no_show_source',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isCheckedIn(): bool
    {
        return $this->status === self::STATUS_CHECKED_IN;
    }
}
