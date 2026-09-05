<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MActivityTypeDetail extends Model
{
    protected $table = 'm_activity_type_detail';
    public $timestamps = false;

    public const PRESENCE_PUNCTUAL = 'punctual';

    public const PRESENCE_WINDOW = 'window';

    public const PRESENCE_INFO = 'info';

    protected $fillable = [
        'name',
        'code',
        'presence',
        'name_preview',
        'sequence',
        'first_program',
        'afternoon_chain',
        'afternoon_default',
        'afternoon_parameter',
        'description',
        'link',
        'link_text',
        'activity_type',
        'role',
        'public_time',
    ];

    protected $casts = [
        'public_time' => 'boolean',
    ];
}