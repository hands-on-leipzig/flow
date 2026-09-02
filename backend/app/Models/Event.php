<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Support\ProgramCatalog;

class Event extends Model
{
    protected $table = 'event';
    public $timestamps = false; // if your table doesn't use created_at / updated_at

    protected $fillable = [
        'id',
        'name',
        'slug',
        'regional_partner',
        'level',
        'season',
        'date',
        'days',
        'link',
        'qrcode',
        'public_helper_search',
        'public_volunteer_data_entry',
        'volunteer_collect_t_shirt',
        'collect_meal',
        'check_in_enabled',
        'check_in_pin',
        'check_in_text_teams',
        'check_in_text_helpers',
        'cockpit_enabled',
        'cockpit_pin',
        'wifi_ssid',
        'wifi_password',
        'wifi_instruction',
        'wifi_qrcode',
        'needs_attention',
        'needs_attention_checked_at'
    ];

    protected $casts = [
        'public_helper_search' => 'boolean',
        'public_volunteer_data_entry' => 'boolean',
        'volunteer_collect_t_shirt' => 'boolean',
        'collect_meal' => 'boolean',
        'check_in_enabled' => 'boolean',
        'cockpit_enabled' => 'boolean',
    ];

    public function checkIns()
    {
        return $this->hasMany(CheckIn::class, 'event');
    }

    protected $with = ['programs'];

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

    public function programs()
    {
        return $this->hasMany(EventProgram::class, 'event')
            // Catalog order lives on m_first_program.sequence, not event_program.first_program.
            ->orderByRaw(ProgramCatalog::sequenceOrderSql('event_program.first_program'))
            ->orderBy('first_program');
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

    public function slideshows()
    {
        return $this->hasMany(SlideShow::class, 'event');
    }

    public function calendar()
    {
        return $this->hasOne(EventCalendar::class, 'event');
    }

    public function volunteerRoster()
    {
        return $this->hasMany(EventVolunteerRoster::class, 'event');
    }

    public function staffingRoles()
    {
        return $this->hasMany(EventStaffingRole::class, 'event');
    }

}
