<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'user';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'nick',
        'subject',
        'dolibarr_id',
        'lang',
        'selection_event',
        'selection_regional_partner',
        'last_login'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login' => 'datetime',
        ];
    }

    public function regionalPartners()
    {
        return $this->belongsToMany(RegionalPartner::class, 'user_regional_partner', 'user', 'regional_partner');
    }

    /**
     * Get user roles from JWT token
     */
    public function getRoles(): array
    {
        $request = request();
        $jwt = $request->attributes->get('jwt');
        
        if (!$jwt || !isset($jwt['resource_access']->flow->roles)) {
            return [];
        }
        
        return $jwt['resource_access']->flow->roles ?? [];
    }
}
