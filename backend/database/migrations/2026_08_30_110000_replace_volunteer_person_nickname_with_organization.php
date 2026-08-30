<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('volunteer_person')) {
            return;
        }

        if (Schema::hasColumn('volunteer_person', 'nickname')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->dropColumn('nickname');
            });
        }

        if (! Schema::hasColumn('volunteer_person', 'organization')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->string('organization', 255)->nullable()->after('mobile');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('volunteer_person')) {
            return;
        }

        if (Schema::hasColumn('volunteer_person', 'organization')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->dropColumn('organization');
            });
        }

        if (! Schema::hasColumn('volunteer_person', 'nickname')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->string('nickname', 100)->nullable()->after('last_name');
            });
        }
    }
};
