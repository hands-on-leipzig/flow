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
        Schema::create('match', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->integer('round');
            $table->integer('match_no');
            $table->integer('table_1');
            $table->integer('table_2');
            $table->integer('table_1_team');
            $table->integer('table_2_team');

            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match');
    }
};
