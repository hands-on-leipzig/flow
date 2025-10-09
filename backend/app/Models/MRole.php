<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MRole extends Model
{
    // Tabelle
    protected $table = 'm_role';

    // Primärschlüssel
    protected $primaryKey = 'id';

    // Keine Timestamps (created_at/updated_at)
    public $timestamps = false;

    // Mass-Assignment Felder
    protected $fillable = [
        'name',
        'name_short',
        'sequence',
        'first_program',
        'description',
        'differentiation_type',
        'differentiation_source',
        'differentiation_parameter',
        'preview_matrix',
        'pdf_export' => 'boolean',
    ];
}