<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Afternoon block order catalog on m_activity_type_detail.
     * NULL = not in the list, 0 = start of a chain, >0 = previous row id.
     * No FK: 0 is a sentinel, not a row.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('m_activity_type_detail', 'chain')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->unsignedInteger('chain')->nullable()->after('first_program');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'chain')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->dropColumn('chain');
            });
        }
    }
};
