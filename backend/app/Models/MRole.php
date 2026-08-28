<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MRole extends Model
{
    protected $table = 'm_role';

    protected $primaryKey = 'id';

    public $timestamps = false;

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
        'pdf_export',
        'staffable',
    ];

    protected $casts = [
        'preview_matrix' => 'boolean',
        'pdf_export' => 'boolean',
        'staffable' => 'boolean',
    ];

    public function staffingRule()
    {
        return $this->hasOne(MStaffingRule::class, 'm_role');
    }
}
