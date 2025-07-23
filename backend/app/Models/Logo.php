<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Logo extends Model
{
    protected $fillable = ['title', 'link', 'path', 'regional_partner'];
    protected $table = 'logo';
    protected $appends = ['url'];
    public $timestamps = false;

    public function getUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }

    public function regionalPartner()
    {
        return $this->belongsTo(RegionalPartner::class, "regional_partner");
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_logo', "logo", "event");
    }
}

