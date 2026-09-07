<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MHelpScreen extends Model
{
    protected $table = 'm_help_screen';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'name',
        'route_path',
        'description',
        'must_do',
        'can_do',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(MHelpAction::class, 'm_help_action_screen', 'help_screen', 'help_action');
    }
}
