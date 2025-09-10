<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
        'slideshow',
    ];

    public function slideshow()
    {
        return $this->belongsTo(SlideShow::class, 'slideshow');
    }
}
