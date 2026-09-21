<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerInquiry extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $table = 'volunteer_inquiry';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'volunteer_person',
        'draht_id',
        'role',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'message',
        'status',
        'decided_at',
        'created_at',
    ];

    protected $casts = [
        'draht_id' => 'integer',
        'created_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function hasAccount(): bool
    {
        return (int) $this->draht_id > 0;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(VolunteerPerson::class, 'volunteer_person');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
