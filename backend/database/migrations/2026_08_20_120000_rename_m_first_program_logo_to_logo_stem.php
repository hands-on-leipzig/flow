<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasColumn('m_first_program', 'logo')
            && ! Schema::hasColumn('m_first_program', 'logo_stem')
        ) {
            Schema::table('m_first_program', function (Blueprint $table) {
                $table->renameColumn('logo', 'logo_stem');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasColumn('m_first_program', 'logo_stem')
            && ! Schema::hasColumn('m_first_program', 'logo')
        ) {
            Schema::table('m_first_program', function (Blueprint $table) {
                $table->renameColumn('logo_stem', 'logo');
            });
        }
    }
};
