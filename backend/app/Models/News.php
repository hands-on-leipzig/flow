<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';
    
    public $timestamps = true; // Only created_at, updated_at column removed from schema
    
    const UPDATED_AT = null; // Explicitly disable updated_at since column doesn't exist
    
    protected $fillable = [
        'title',
        'text',
        'link',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        // No updated_at cast
    ];

    /**
     * Get the users who have read this news.
     */
    public function readByUsers()
    {
        return $this->belongsToMany(User::class, 'news_user', 'news_id', 'user_id')
            ->withPivot('read_at')
            ->withTimestamps();
    }
}

