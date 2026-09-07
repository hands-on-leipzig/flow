<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function actions(): HasMany
    {
        return $this->hasMany(MHelpAction::class, 'help_screen');
    }
}
