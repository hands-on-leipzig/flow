<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MHelpTopic extends Model
{
    protected $table = 'm_help_topic';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(MHelpAction::class, 'help_topic');
    }
}
