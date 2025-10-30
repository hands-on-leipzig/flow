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
        Schema::create('contao_public_rounds', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->boolean('vr1')->default(true);
            $table->boolean('vr2')->default(false);
            $table->boolean('vr3')->default(false);
            $table->boolean('af')->default(false);
            $table->boolean('vf')->default(false);
            $table->boolean('hf')->default(false);

            $table->foreign('event_id')->references('id')->on('event')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contao_public_rounds');
    }
};
