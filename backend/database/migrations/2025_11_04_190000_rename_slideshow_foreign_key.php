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
        Schema::table('slide', function (Blueprint $table) {
            try {
                $table->dropForeign(['slideshow']);
            } catch (\Throwable $e) {
                // FK might not exist or have different name; ignore
            }
            
            // Only rename if column exists
            if (Schema::hasColumn('slide', 'slideshow')) {
                $table->renameColumn('slideshow', 'slideshow_id');
            }
        });
        
        // Try to add foreign key separately (may fail if column types don't match)
        try {
            Schema::table('slide', function (Blueprint $table) {
                $table->foreign('slideshow_id')->references('id')->on('slideshow')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key can't be added (type mismatch or column doesn't exist)
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slide', function (Blueprint $table) {
            $table->dropForeign(['slideshow_id']);
            $table->renameColumn('slideshow_id', 'slideshow');
            $table->foreign('slideshow')->references('id')->on('slideshows')->onDelete('cascade');
        });
    }
};

