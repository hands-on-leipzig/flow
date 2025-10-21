<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsUser extends Model
{
    protected $table = 'news_user';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'news_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Get the news item.
     */
    public function news()
    {
        return $this->belongsTo(MNews::class, 'news_id');
    }

    /**
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
