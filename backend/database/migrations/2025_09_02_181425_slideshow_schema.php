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
        Schema::create('slideshow', function (Blueprint $table) {
            $table->id();

            $table->json('name');
            $table->unsignedInteger('event');
            $table->foreign('event')->references('id')->on('event');
        });

        Schema::create('slide', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('content');
            $table->unsignedBigInteger('slideshow_id');
            $table->foreign('slideshow_id')->references('id')->on('slideshow')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slideshow');
        Schema::dropIfExists('slide');
    }
};
