<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MNews extends Model
{
    protected $table = 'm_news';

    protected $fillable = [
        'title',
        'text',
        'link',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
