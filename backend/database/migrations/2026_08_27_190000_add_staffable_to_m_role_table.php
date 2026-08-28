<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_role')) {
            return;
        }

        if (! Schema::hasColumn('m_role', 'staffable')) {
            Schema::table('m_role', function (Blueprint $table) {
                $table->boolean('staffable')->default(false)->after('pdf_export');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_role') && Schema::hasColumn('m_role', 'staffable')) {
            Schema::table('m_role', function (Blueprint $table) {
                $table->dropColumn('staffable');
            });
        }
    }
};
