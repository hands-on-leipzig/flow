<?php

namespace App\Models;

use App\Support\FlowAccess;
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
        'name',
        'email',
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
     * Roles from the current request JWT (client + realm).
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return FlowAccess::rolesFromJwt(request()->attributes->get('jwt'));
    }

    public function isFlowAdmin(): bool
    {
        return FlowAccess::isAdmin($this->getRoles());
    }

    public function isFlowUser(): bool
    {
        return FlowAccess::isFlowUser($this->getRoles());
    }

    public function hasRegionalPartnerAccess(int $regionalPartnerId): bool
    {
        if ($this->isFlowAdmin()) {
            return true;
        }

        return $this->regionalPartners()
            ->where('regional_partner.id', $regionalPartnerId)
            ->exists();
    }

    public function hasEventAccess(int $eventId): bool
    {
        if ($this->isFlowAdmin()) {
            return true;
        }

        return $this->regionalPartners()
            ->whereHas('events', function ($query) use ($eventId) {
                $query->where('id', $eventId);
            })
            ->exists();
    }
}
