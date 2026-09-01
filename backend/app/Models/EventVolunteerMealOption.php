<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVolunteerMealOption extends Model
{
    protected $table = 'event_volunteer_meal_option';

    protected $fillable = [
        'event',
        'value',
        'label',
        'sequence',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
