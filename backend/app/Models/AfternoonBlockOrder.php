<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfternoonBlockOrder extends Model
{
    public $timestamps = false;

    protected $table = 'afternoon_block_order';

    protected $fillable = [
        'plan',
        'activity_type_detail',
        'sequence',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan');
    }

    public function activityTypeDetail()
    {
        return $this->belongsTo(MActivityTypeDetail::class, 'activity_type_detail');
    }
}
