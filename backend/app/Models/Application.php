<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    protected $fillable = [
        'name',
        'description',
        'contact_email',
        'webhook_url',
        'allowed_ips',
        'rate_limit',
        'is_active',
    ];

    protected $casts = [
        'allowed_ips' => 'array',
        'is_active' => 'boolean',
        'rate_limit' => 'integer',
    ];

    /**
     * Get all API keys for this application
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
    
    /**
     * Get active API keys only
     */
    public function activeApiKeys(): HasMany
    {
        return $this->apiKeys()->where('is_active', true);
    }
}

