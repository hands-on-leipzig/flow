<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerInquiry extends Model
{
    protected $table = 'volunteer_inquiry';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'volunteer_person',
        'role',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'message',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(VolunteerPerson::class, 'volunteer_person');
    }
}
