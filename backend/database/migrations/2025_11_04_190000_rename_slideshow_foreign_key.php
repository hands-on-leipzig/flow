<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key first (if exists)
        try {
            Schema::table('slide', function (Blueprint $table) {
                $table->dropForeign(['slideshow']);
            });
        } catch (\Throwable $e) {
            // FK might not exist or have different name; ignore
        }
        
        // Rename column if it exists
        if (Schema::hasColumn('slide', 'slideshow') && !Schema::hasColumn('slide', 'slideshow_id')) {
            try {
                Schema::table('slide', function (Blueprint $table) {
                    $table->renameColumn('slideshow', 'slideshow_id');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be renamed; ignore
            }
        }
        
        // Try to add foreign key separately (may fail if column types don't match)
        if (Schema::hasColumn('slide', 'slideshow_id')) {
            try {
                Schema::table('slide', function (Blueprint $table) {
                    $table->foreign('slideshow_id')->references('id')->on('slideshow')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore if foreign key can't be added (type mismatch)
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key first (if exists)
        try {
            Schema::table('slide', function (Blueprint $table) {
                $table->dropForeign(['slideshow_id']);
            });
        } catch (\Throwable $e) {
            // FK might not exist; ignore
        }
        
        // Rename column if it exists
        if (Schema::hasColumn('slide', 'slideshow_id') && !Schema::hasColumn('slide', 'slideshow')) {
            try {
                Schema::table('slide', function (Blueprint $table) {
                    $table->renameColumn('slideshow_id', 'slideshow');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be renamed; ignore
            }
        }
        
        // Try to add foreign key separately
        if (Schema::hasColumn('slide', 'slideshow')) {
            try {
                Schema::table('slide', function (Blueprint $table) {
                    $table->foreign('slideshow')->references('id')->on('slideshows')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore if foreign key can't be added (type mismatch or table doesn't exist)
            }
        }
    }
};

