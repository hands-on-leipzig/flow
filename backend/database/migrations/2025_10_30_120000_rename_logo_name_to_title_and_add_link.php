<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename 'name' column to 'title' if it exists and 'title' doesn't exist
        if (Schema::hasColumn('logo', 'name') && !Schema::hasColumn('logo', 'title')) {
            DB::statement('ALTER TABLE `logo` CHANGE COLUMN `name` `title` VARCHAR(100) NULL');
        }

        // Add 'link' column if it doesn't exist (after title if title exists, otherwise after id)
        Schema::table('logo', function (Blueprint $table) {
            if (!Schema::hasColumn('logo', 'link')) {
                if (Schema::hasColumn('logo', 'title')) {
                    $table->string('link', 500)->nullable()->after('title');
                } else {
                    $table->string('link', 500)->nullable()->after('id');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename 'title' back to 'name' if it exists
        if (Schema::hasColumn('logo', 'title') && !Schema::hasColumn('logo', 'name')) {
            DB::statement('ALTER TABLE `logo` CHANGE COLUMN `title` `name` VARCHAR(100) NULL');
        }

        Schema::table('logo', function (Blueprint $table) {
            // Remove 'link' column if it exists
            if (Schema::hasColumn('logo', 'link')) {
                $table->dropColumn('link');
            }
        });
    }
};

