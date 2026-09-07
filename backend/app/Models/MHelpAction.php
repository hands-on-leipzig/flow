<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MHelpAction extends Model
{
    protected $table = 'm_help_action';

    public $timestamps = false;

    protected $fillable = [
        'help_screen',
        'help_topic',
        'title',
        'sort_order',
    ];

    protected $casts = [
        'help_screen' => 'integer',
        'help_topic' => 'integer',
        'sort_order' => 'integer',
    ];

    public function screen(): BelongsTo
    {
        return $this->belongsTo(MHelpScreen::class, 'help_screen');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(MHelpTopic::class, 'help_topic');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MHelpActionStep::class, 'help_action');
    }
}
