<?php

// app/Models/SGenerator.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SGenerator extends Model
{
    protected $table = 's_generator';

    protected $fillable = [
        'plan',
        'start',
        'end',
        'mode',
    ];

    public $timestamps = false;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan');
    }
}