<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_first_program', function (Blueprint $table) {
            if (Schema::hasColumn('m_first_program', 'family')) {
                $table->dropColumn('family');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_first_program', function (Blueprint $table) {
            if (! Schema::hasColumn('m_first_program', 'family')) {
                $table->string('family', 32)->nullable()->after('letter');
            }
        });
    }
};
