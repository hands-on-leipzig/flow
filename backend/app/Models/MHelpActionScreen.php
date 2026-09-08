<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MHelpActionScreen extends Model
{
    protected $table = 'm_help_action_screen';

    public $timestamps = false;

    protected $fillable = [
        'help_action',
        'help_screen',
    ];

    protected $casts = [
        'help_action' => 'integer',
        'help_screen' => 'integer',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(MHelpAction::class, 'help_action');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(MHelpScreen::class, 'help_screen');
    }
}
