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
            $table->dropForeign(['slideshow']);
            $table->renameColumn('slideshow', 'slideshow_id');
            $table->foreign('slideshow_id')->references('id')->on('slideshow')->onDelete('cascade');
        });
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

