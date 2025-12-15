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
        // Add explore_group column to activity_group table
        Schema::table('activity_group', function (Blueprint $table) {
            $table->unsignedTinyInteger('explore_group')->nullable()->after('plan');
        });

        // Add explore_group column to activity table
        Schema::table('activity', function (Blueprint $table) {
            $table->unsignedTinyInteger('explore_group')->nullable()->after('activity_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove explore_group column from activity table
        Schema::table('activity', function (Blueprint $table) {
            $table->dropColumn('explore_group');
        });

        // Remove explore_group column from activity_group table
        Schema::table('activity_group', function (Blueprint $table) {
            $table->dropColumn('explore_group');
        });
    }
};
