<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_volunteer_field') && ! Schema::hasColumn('event_volunteer_field', 'public_form')) {
            Schema::table('event_volunteer_field', function (Blueprint $table) {
                $table->boolean('public_form')->default(false)->after('sequence');
            });
            DB::table('event_volunteer_field')->update(['public_form' => false]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_volunteer_field') && Schema::hasColumn('event_volunteer_field', 'public_form')) {
            Schema::table('event_volunteer_field', function (Blueprint $table) {
                $table->dropColumn('public_form');
            });
        }
    }
};
