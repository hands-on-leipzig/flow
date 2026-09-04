<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalog match-plan row (m_match). Not live plan pairings — those stay in `match`.
 */
class MMatch extends Model
{
    protected $table = 'm_match';

    public $timestamps = false;

    protected $fillable = [
        'first_program',
        'teams',
        'lanes',
        'tables',
        'comment',
        'round',
        'match_no',
        'table_1',
        'table_2',
        'table_1_team',
        'table_2_team',
    ];

    protected $casts = [
        'first_program' => 'integer',
        'teams' => 'integer',
        'lanes' => 'integer',
        'tables' => 'integer',
        'round' => 'integer',
        'match_no' => 'integer',
        'table_1' => 'integer',
        'table_2' => 'integer',
        'table_1_team' => 'integer',
        'table_2_team' => 'integer',
    ];
}
