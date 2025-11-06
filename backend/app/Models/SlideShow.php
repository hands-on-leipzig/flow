<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class SlideShow extends Model
{
    protected $table = 'slideshow';
    public $timestamps = false; // if your table doesn't use created_at / updated_at

    protected $fillable = [
        'id',
        'name',
        'event',
        'transition_time',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function slides()
    {
        return $this->hasMany(Slide::class, 'slideshow_id')->orderBy('order');
    }
}
