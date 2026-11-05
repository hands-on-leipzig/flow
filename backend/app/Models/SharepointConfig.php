<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharepointConfig extends Model
{
    protected $table = 'sharepoint_config';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_secret',
        'folder_url',
        'folder_url_de',
        'folder_url_en',
        'is_enabled',
        'cached_drive_id',
        'cached_root_item_id',
        'cached_root_name',
        'cached_roots',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'cached_roots' => 'array',
        ];
    }

    public static function instance(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
