<?php

namespace App\Models;

use App\Support\FlowAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

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

    /**
     * Draht membership covers every event of that regional partner.
     * Invitations are stored per event in event_user_access.
     */
    public function hasPermission(string $permission): bool
    {
        $column = FlowAccess::permissionColumn($permission);

        $viaDraht = DB::table('user_regional_partner')
            ->where('user', $this->id)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->where($column, true)
            ->exists();

        if ($viaDraht) {
            return true;
        }

        return DB::table('event_user_access')
            ->where('user', $this->id)
            ->where($column, true)
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function accessibleEventIds(string $permission): array
    {
        $column = FlowAccess::permissionColumn($permission);

        $direct = DB::table('event_user_access')
            ->where('user', $this->id)
            ->where($column, true)
            ->pluck('event')
            ->all();

        $rpIds = DB::table('user_regional_partner')
            ->where('user', $this->id)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->where($column, true)
            ->pluck('regional_partner')
            ->all();

        $viaPartner = $rpIds === []
            ? []
            : DB::table('event')->whereIn('regional_partner', $rpIds)->pluck('id')->all();

        return array_values(array_unique(array_map('intval', [...$direct, ...$viaPartner])));
    }

    public function hasRegionalPartnerAccess(int $regionalPartnerId, string $permission = FlowAccess::PERMISSION_FLOW): bool
    {
        if ($this->isFlowAdmin()) {
            return true;
        }

        $column = FlowAccess::permissionColumn($permission);

        $viaDraht = DB::table('user_regional_partner')
            ->where('user', $this->id)
            ->where('regional_partner', $regionalPartnerId)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->where($column, true)
            ->exists();

        if ($viaDraht) {
            return true;
        }

        return DB::table('event_user_access as access')
            ->join('event', 'access.event', '=', 'event.id')
            ->where('access.user', $this->id)
            ->where('event.regional_partner', $regionalPartnerId)
            ->where('access.'.$column, true)
            ->exists();
    }

    public function hasEventAccess(int $eventId, string $permission = FlowAccess::PERMISSION_FLOW): bool
    {
        if ($this->isFlowAdmin()) {
            return true;
        }

        $column = FlowAccess::permissionColumn($permission);

        $direct = DB::table('event_user_access')
            ->where('user', $this->id)
            ->where('event', $eventId)
            ->where($column, true)
            ->exists();

        if ($direct) {
            return true;
        }

        $regionalPartnerId = (int) DB::table('event')->where('id', $eventId)->value('regional_partner');
        if ($regionalPartnerId <= 0) {
            return false;
        }

        return DB::table('user_regional_partner')
            ->where('user', $this->id)
            ->where('regional_partner', $regionalPartnerId)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->where($column, true)
            ->exists();
    }

    /**
     * Draht contacts (and admins) decide who else gets access to their regional partner.
     */
    public function canManageRegionalPartner(int $regionalPartnerId): bool
    {
        if ($this->isFlowAdmin()) {
            return true;
        }

        return DB::table('user_regional_partner')
            ->where('user', $this->id)
            ->where('regional_partner', $regionalPartnerId)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->exists();
    }
}
