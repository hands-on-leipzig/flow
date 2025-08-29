<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
        'days',
        'qrcode',
        'wifi_ssid',
        'wifi_password',
    ];

    public function regionalPartner()
    {
        return $this->belongsTo(RegionalPartner::class, 'regional_partner');
    }

    public function seasonRel()
    {
        return $this->belongsTo(MSeason::class, 'season');
    }

    public function levelRel()
    {
        return $this->belongsTo(MLevel::class, 'level');
    }

    public function logos()
    {
        return $this->belongsToMany(Logo::class, 'event_logo', "event", "logo");
    }

    public function teams()
    {
        return $this->hasMany(Team::class, "event");
    }

    public function tableNames()
    {
        return $this->hasMany(TableEvent::class, 'event');
    }

}
