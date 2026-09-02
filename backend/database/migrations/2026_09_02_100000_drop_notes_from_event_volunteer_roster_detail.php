<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_volunteer_roster_detail') && Schema::hasColumn('event_volunteer_roster_detail', 'notes')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_volunteer_roster_detail') && ! Schema::hasColumn('event_volunteer_roster_detail', 'notes')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('photo_consent');
            });
        }
    }
};
