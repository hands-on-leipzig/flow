<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityGroup extends Model
{
    protected $table = 'activity_group';
    public $timestamps = false;

    protected $fillable = [
        'activity_type_detail',
        'plan',
        'explore_group',
    ];

    // Define relationship to Plan
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    // Define relationship to ActivityTypeDetail
    public function detail()
    {
        return $this->belongsTo(MActivityTypeDetail::class, 'activity_type_detail');
    }

    // Define relationship to Activities
    public function activities()
    {
        return $this->hasMany(Activity::class, 'activity_group');
    }
}