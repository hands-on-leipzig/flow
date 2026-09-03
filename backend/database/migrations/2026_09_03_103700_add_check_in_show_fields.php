<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'check_in_show_team_photo')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('check_in_show_team_photo')->default(true)->after('check_in_text_helpers');
                $table->boolean('check_in_show_team_meal')->default(false)->after('check_in_show_team_photo');
                $table->boolean('check_in_show_helper_photo')->default(true)->after('check_in_show_team_meal');
                $table->boolean('check_in_show_helper_meal')->default(false)->after('check_in_show_helper_photo');
                $table->boolean('check_in_show_helper_t_shirt')->default(false)->after('check_in_show_helper_meal');
            });
        }

        if (Schema::hasTable('event_team_field') && ! Schema::hasColumn('event_team_field', 'check_in_show')) {
            Schema::table('event_team_field', function (Blueprint $table) {
                $table->boolean('check_in_show')->default(false)->after('public_form');
            });
        }

        if (Schema::hasTable('event_volunteer_field') && ! Schema::hasColumn('event_volunteer_field', 'check_in_show')) {
            Schema::table('event_volunteer_field', function (Blueprint $table) {
                $table->boolean('check_in_show')->default(false)->after('public_form');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'check_in_show_team_photo')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn([
                    'check_in_show_team_photo',
                    'check_in_show_team_meal',
                    'check_in_show_helper_photo',
                    'check_in_show_helper_meal',
                    'check_in_show_helper_t_shirt',
                ]);
            });
        }

        if (Schema::hasColumn('event_team_field', 'check_in_show')) {
            Schema::table('event_team_field', function (Blueprint $table) {
                $table->dropColumn('check_in_show');
            });
        }

        if (Schema::hasColumn('event_volunteer_field', 'check_in_show')) {
            Schema::table('event_volunteer_field', function (Blueprint $table) {
                $table->dropColumn('check_in_show');
            });
        }
    }
};
