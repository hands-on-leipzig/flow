<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_staffing_rule') && Schema::hasColumn('m_staffing_rule', 'max')) {
            Schema::table('m_staffing_rule', function (Blueprint $table) {
                $table->dropColumn('max');
            });
        }

        if (Schema::hasTable('event_staffing_role') && Schema::hasColumn('event_staffing_role', 'max')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->dropColumn('max');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_staffing_rule') && ! Schema::hasColumn('m_staffing_rule', 'max')) {
            Schema::table('m_staffing_rule', function (Blueprint $table) {
                $table->unsignedSmallInteger('max')->after('best');
            });
        }

        if (Schema::hasTable('event_staffing_role') && ! Schema::hasColumn('event_staffing_role', 'max')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->unsignedSmallInteger('max')->after('best');
            });
        }
    }
};
