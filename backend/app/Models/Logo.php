<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Logo extends Model
{
    protected $fillable = ['title', 'link', 'file_path', 'regional_partner_id'];
    protected $table = 'logo';

    public function regionalPartner()
    {
        return $this->belongsTo(RegionalPartner::class, "regional_partner");
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_logo', "logo", "event");
    }
}

