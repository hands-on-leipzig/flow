<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Afternoon catalog on m_activity_type_detail:
     * afternoon_chain NULL = not an afternoon block, 0 = chain start, >0 = predecessor id.
     * afternoon_default = default time order (not m_activity_type_detail.sequence).
     */
    public function up(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'chain')
            && ! Schema::hasColumn('m_activity_type_detail', 'afternoon_chain')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->renameColumn('chain', 'afternoon_chain');
            });
        }

        if (! Schema::hasColumn('m_activity_type_detail', 'afternoon_default')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->unsignedSmallInteger('afternoon_default')->nullable()->after('afternoon_chain');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'afternoon_default')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->dropColumn('afternoon_default');
            });
        }

        if (Schema::hasColumn('m_activity_type_detail', 'afternoon_chain')
            && ! Schema::hasColumn('m_activity_type_detail', 'chain')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->renameColumn('afternoon_chain', 'chain');
            });
        }
    }
};
