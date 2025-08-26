<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activity';
    public $timestamps = false;

    protected $fillable = [
        'activity_group',
        'start',
        'end',
        'room_type',
        'jury_lane',
        'jury_team',
        'table_1',
        'table_1_team',
        'table_2',
        'table_2_team',
        'activity_type_detail',
        'extra_block',
    ];

    // Define relationship to ActivityGroup
    public function group()
    {
        return $this->belongsTo(ActivityGroup::class, 'activity_group');
    }

    // Define relationship to ActivityTypeDetail
    public function detail()
    {
        return $this->belongsTo(MActivityTypeDetail::class, 'activity_type_detail');
    }
}