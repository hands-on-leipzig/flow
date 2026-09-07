<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MHelpActionStep extends Model
{
    protected $table = 'm_help_action_step';

    public $timestamps = false;

    protected $fillable = [
        'help_action',
        'body',
        'sort_order',
    ];

    protected $casts = [
        'help_action' => 'integer',
        'sort_order' => 'integer',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(MHelpAction::class, 'help_action');
    }
}
