<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MHelpAction extends Model
{
    protected $table = 'm_help_action';

    public $timestamps = false;

    protected $fillable = [
        'help_topic',
        'title',
        'body',
        'sort_order',
    ];

    protected $casts = [
        'help_topic' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function payloadEagerLoad(): array
    {
        return [
            'stat',
            'topic',
            'screens' => fn ($q) => $q->orderBy('name')->orderBy('id'),
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(MHelpTopic::class, 'help_topic');
    }

    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(MHelpScreen::class, 'm_help_action_screen', 'help_action', 'help_screen');
    }

    public function stat(): HasOne
    {
        return $this->hasOne(HelpActionStat::class, 'help_action');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        $screens = $this->relationLoaded('screens')
            ? $this->screens
            : $this->screens()->orderBy('name')->orderBy('id')->get();
        $sorted = $screens->sortBy([
            ['name', 'asc'],
            ['id', 'asc'],
        ])->values();
        $topic = $this->relationLoaded('topic') ? $this->topic : $this->topic()->first();
        $stat = $this->relationLoaded('stat') ? $this->stat : $this->stat()->first();

        return [
            'id' => (int) $this->id,
            'help_topic' => (int) $this->help_topic,
            'title' => $this->title,
            'body' => $this->body,
            'open_count' => (int) ($stat?->open_count ?? 0),
            'helpful_yes' => (int) ($stat?->helpful_yes ?? 0),
            'helpful_no' => (int) ($stat?->helpful_no ?? 0),
            'sort_order' => (int) $this->sort_order,
            'help_screen_ids' => $sorted->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'screens' => $sorted->map(fn (MHelpScreen $screen) => [
                'id' => (int) $screen->id,
                'key' => $screen->key,
                'name' => $screen->name,
                'route_path' => $screen->route_path,
            ])->values()->all(),
            'topic' => $topic ? [
                'id' => (int) $topic->id,
                'key' => $topic->key,
                'name' => $topic->name,
            ] : null,
        ];
    }
}
