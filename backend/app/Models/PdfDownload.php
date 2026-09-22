<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfDownload extends Model
{
    protected $table = 's_pdf_download';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'user',
        'kind',
        'created',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user');
    }
}
