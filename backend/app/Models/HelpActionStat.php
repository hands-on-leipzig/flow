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
        $stat = static::ensure($actionId);
        $stat->increment($column);
        $stat->refresh();

        return $stat;
    }

    /**
     * Replace this browser's Ja/Nein. Client-trusted `previous` (null = first vote).
     */
    public static function replaceHelpful(int $actionId, bool $helpful, ?bool $previous): self
    {
        $stat = static::ensure($actionId);

        if ($previous === $helpful) {
            return $stat;
        }

        if ($previous !== null) {
            $old = $previous ? 'helpful_yes' : 'helpful_no';
            $stat->{$old} = max(0, (int) $stat->{$old} - 1);
        }

        $new = $helpful ? 'helpful_yes' : 'helpful_no';
        $stat->{$new} = (int) $stat->{$new} + 1;
        $stat->save();

        return $stat;
    }

    private static function ensure(int $actionId): self
    {
        return static::query()->firstOrCreate(
            ['help_action' => $actionId],
            [
                'open_count' => 0,
                'helpful_yes' => 0,
                'helpful_no' => 0,
            ]
        );
    }
}
