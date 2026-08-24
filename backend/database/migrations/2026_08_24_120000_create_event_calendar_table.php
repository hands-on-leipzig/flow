<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_calendar')) {
            return;
        }

        Schema::create('event_calendar', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->date('date');
            $table->string('uid', 255);
            $table->unsignedInteger('sequence')->default(0);
            $table->longText('vevent');
            $table->timestamp('built_at');

            $table->unique('event', 'event_calendar_event_unique');
            $table->index('date', 'event_calendar_date_index');
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_calendar');
    }
};
