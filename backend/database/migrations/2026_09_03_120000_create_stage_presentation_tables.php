<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per program per event, carrying the lock. The lock lives here
        // and not on the slot rows because an empty selection must be lockable.
        if (! Schema::hasTable('stage_presentation')) {
            Schema::create('stage_presentation', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program');
                $table->boolean('locked')->default(false);
                $table->timestamps();

                $table->unique(['event', 'first_program'], 'stage_presentation_event_program_unique');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('stage_presentation_team')) {
            Schema::create('stage_presentation_team', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('stage_presentation');
                $table->unsignedTinyInteger('slot');
                $table->unsignedInteger('team')->nullable();

                $table->unique(['stage_presentation', 'slot'], 'stage_presentation_team_slot_unique');
                $table->foreign('stage_presentation')->references('id')->on('stage_presentation')->onDelete('cascade');
                $table->foreign('team')->references('id')->on('team')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_presentation_team');
        Schema::dropIfExists('stage_presentation');
    }
};
