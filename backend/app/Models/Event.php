<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'event';
    public $timestamps = false; // if your table doesn't use created_at / updated_at

    protected $fillable = [
        'id',
        'name',
        'slug',
        'event_explore',
        'event_challenge',
        'regional_partner',
        'level',
        'season',
        'date',
        'enddate',
        'days',
        'qrcode',
    ];

    public function regionalPartner()
    {
        return $this->belongsTo(RegionalPartner::class, 'regional_partner');
    }

    public function season()
    {
        return $this->belongsTo(Season::class, 'season');
    }

    public function logos()
    {
        return $this->belongsToMany(Logo::class, 'event_logo', "event", "logo");
    }

}
