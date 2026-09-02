<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'volunteer_collect_t_shirt')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('volunteer_collect_t_shirt')->default(true)->after('public_volunteer_data_entry');
            });
        }
        if (! Schema::hasColumn('event', 'volunteer_collect_meal')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('volunteer_collect_meal')->default(true)->after('volunteer_collect_t_shirt');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'volunteer_collect_meal')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('volunteer_collect_meal');
            });
        }
        if (Schema::hasColumn('event', 'volunteer_collect_t_shirt')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('volunteer_collect_t_shirt');
            });
        }
    }
};
