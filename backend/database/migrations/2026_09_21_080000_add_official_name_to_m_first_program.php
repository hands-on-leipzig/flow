<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('m_first_program', 'official_name')) {
            Schema::table('m_first_program', function (Blueprint $table) {
                $table->string('official_name')->nullable()->after('display_name');
            });
        }

        DB::table('m_first_program')
            ->whereNotNull('display_name')
            ->where('display_name', '<>', '')
            ->update(['official_name' => DB::raw("CONCAT('<i>FIRST</i> LEGO League ', display_name)")]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('m_first_program', 'official_name')) {
            return;
        }

        Schema::table('m_first_program', function (Blueprint $table) {
            $table->dropColumn('official_name');
        });
    }
};
