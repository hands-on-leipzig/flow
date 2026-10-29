<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scor_noteworthy')) {
            Schema::create('scor_noteworthy', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('team');
                $table->unique(['event', 'team'], 'scor_noteworthy_event_team');
            });
        }
        if (!Schema::hasTable('scor_stage_pick')) {
            Schema::create('scor_stage_pick', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedSmallInteger('slot');
                $table->unsignedInteger('team');
                $table->unique(['event', 'slot'], 'scor_stage_pick_event_slot');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scor_stage_pick');
        Schema::dropIfExists('scor_noteworthy');
    }
};
