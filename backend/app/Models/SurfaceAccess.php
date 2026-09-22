<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurfaceAccess extends Model
{
    protected $table = 's_surface_access';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'kind',
        'access_date',
        'access_time',
        'user_agent',
        'referrer',
        'ip_hash',
        'accept_language',
        'screen_width',
        'screen_height',
        'viewport_width',
        'viewport_height',
        'device_pixel_ratio',
        'touch_support',
        'connection_type',
        'source',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
