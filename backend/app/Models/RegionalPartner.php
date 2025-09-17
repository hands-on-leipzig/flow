<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegionalPartner extends Model
{
    protected $table = 'regional_partner';

    public $timestamps = false;
    
    protected $fillable = [
        'name',
        'region',
        'dolibarr_id'
    ];
    
    public function events()
    {
        return $this->hasMany(Event::class, 'regional_partner');
    }

    public function logos()
    {
        return $this->hasMany(Logo::class, "regional_partner");
    }

}
