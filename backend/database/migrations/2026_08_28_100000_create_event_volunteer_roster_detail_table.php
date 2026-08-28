<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_volunteer_roster_detail')) {
            return;
        }

        Schema::create('event_volunteer_roster_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event_volunteer_roster');
            $table->string('t_shirt_cut', 20)->nullable();
            $table->string('t_shirt_size', 10)->nullable();
            $table->string('meal', 30)->nullable();
            $table->boolean('eve_meeting')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('event_volunteer_roster', 'event_volunteer_roster_detail_roster_unique');
            $table->foreign('event_volunteer_roster')
                ->references('id')
                ->on('event_volunteer_roster')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_volunteer_roster_detail');
    }
};
