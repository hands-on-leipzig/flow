<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slide extends Model
{
    protected $table = 'slide';
    public $timestamps = false; // if your table doesn't use created_at / updated_at

    protected $fillable = [
        'id',
        'name',
        'type',
        'content',
        'order',
        'slideshow_id',
        'active',
    ];

    public function slideshow(): BelongsTo
    {
        return $this->belongsTo(SlideShow::class, 'slideshow_id');
    }
}
