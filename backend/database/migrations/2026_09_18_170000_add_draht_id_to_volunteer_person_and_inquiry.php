<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('volunteer_person') && ! Schema::hasColumn('volunteer_person', 'draht_id')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->unsignedInteger('draht_id')->nullable()->after('regional_partner');
                $table->unique(['regional_partner', 'draht_id'], 'volunteer_person_rp_draht_unique');
            });
        }

        if (Schema::hasTable('volunteer_inquiry') && ! Schema::hasColumn('volunteer_inquiry', 'draht_id')) {
            Schema::table('volunteer_inquiry', function (Blueprint $table) {
                $table->unsignedInteger('draht_id')->nullable()->after('volunteer_person');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('volunteer_person') && Schema::hasColumn('volunteer_person', 'draht_id')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->dropUnique('volunteer_person_rp_draht_unique');
                $table->dropColumn('draht_id');
            });
        }

        if (Schema::hasTable('volunteer_inquiry') && Schema::hasColumn('volunteer_inquiry', 'draht_id')) {
            Schema::table('volunteer_inquiry', function (Blueprint $table) {
                $table->dropColumn('draht_id');
            });
        }
    }
};
