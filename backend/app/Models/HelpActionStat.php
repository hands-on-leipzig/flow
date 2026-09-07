<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpActionStat extends Model
{
    protected $table = 'help_action_stat';

    protected $primaryKey = 'help_action';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'help_action',
        'open_count',
        'helpful_yes',
        'helpful_no',
    ];

    protected $casts = [
        'help_action' => 'integer',
        'open_count' => 'integer',
        'helpful_yes' => 'integer',
        'helpful_no' => 'integer',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(MHelpAction::class, 'help_action');
    }

    public static function bump(int $actionId, string $column): self
    {
        $stat = static::query()->firstOrCreate(
            ['help_action' => $actionId],
            [
                'open_count' => 0,
                'helpful_yes' => 0,
                'helpful_no' => 0,
            ]
        );
        $stat->increment($column);
        $stat->refresh();

        return $stat;
    }
}
