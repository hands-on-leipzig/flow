<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventProgram extends Model
{
    protected $table = 'event_program';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'first_program',
        'draht_id',
        'contao_id',
    ];

    protected $with = ['firstProgram'];

    protected $appends = [
        'name',
        'display_name',
        'letter',
        'sequence',
        'color_hex',
        'logo_stem',
        'logo_white',
    ];

    protected $hidden = [
        'firstProgram',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event');
    }

    public function firstProgram()
    {
        return $this->belongsTo(FirstProgram::class, 'first_program');
    }

    public function getNameAttribute(): ?string
    {
        return $this->firstProgram?->name;
    }

    public function getDisplayNameAttribute(): ?string
    {
        return $this->firstProgram?->display_name;
    }

    public function getLetterAttribute(): ?string
    {
        return $this->firstProgram?->letter;
    }

    public function getSequenceAttribute(): ?int
    {
        return $this->firstProgram?->sequence;
    }

    public function getColorHexAttribute(): ?string
    {
        return $this->firstProgram?->color_hex;
    }

    public function getLogoStemAttribute(): ?string
    {
        return $this->firstProgram?->logo_stem;
    }

    public function getLogoWhiteAttribute(): ?string
    {
        return $this->firstProgram?->logo_white;
    }
}
