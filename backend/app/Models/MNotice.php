<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MNotice extends Model
{
    protected $table = 'm_notice';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'kind',
        'condition_key',
        'help_screen',
        'title',
        'body',
        'sort_order',
        'time_mode',
        'abs_start',
        'abs_end',
        'rel_start_days',
        'rel_end_days',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'rel_start_days' => 'integer',
        'rel_end_days' => 'integer',
        'abs_start' => 'date',
        'abs_end' => 'date',
    ];

    public function helpScreen(): BelongsTo
    {
        return $this->belongsTo(MHelpScreen::class, 'help_screen');
    }

    public function hiddenForEvents(): HasMany
    {
        return $this->hasMany(EventNoticeHidden::class, 'notice');
    }
}
