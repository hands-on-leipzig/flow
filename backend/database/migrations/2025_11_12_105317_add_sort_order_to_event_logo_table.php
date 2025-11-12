<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add sort_order column to event_logo table if it doesn't exist
        if (Schema::hasTable('event_logo') && !Schema::hasColumn('event_logo', 'sort_order')) {
            Schema::table('event_logo', function (Blueprint $table) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('logo');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('event_logo', 'sort_order')) {
            Schema::table('event_logo', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
