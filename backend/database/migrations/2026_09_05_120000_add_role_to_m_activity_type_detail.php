<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who does that job: one catalog role per activity type, or null if nobody.
     */
    public function up(): void
    {
        if (! Schema::hasTable('m_activity_type_detail')
            || Schema::hasColumn('m_activity_type_detail', 'role')) {
            return;
        }

        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('role')->nullable()->after('activity_type');
            $table->foreign('role')->references('id')->on('m_role')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_activity_type_detail')
            || ! Schema::hasColumn('m_activity_type_detail', 'role')) {
            return;
        }

        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->dropForeign(['role']);
            $table->dropColumn('role');
        });
    }
};
