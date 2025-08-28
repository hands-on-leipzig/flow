<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MActivityTypeDetail extends Model
{
    protected $table = 'm_activity_type_detail';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'name_preview',
        'sequence',
        'first_program',
        'description',
        'link',
        'link_text',
        'activity_type',
    ];

    // Optional: Define relationship to groups or activities if needed later
}