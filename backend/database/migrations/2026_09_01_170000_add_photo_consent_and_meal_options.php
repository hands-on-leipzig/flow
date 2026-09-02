<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_volunteer_roster_detail') && ! Schema::hasColumn('event_volunteer_roster_detail', 'photo_consent')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->boolean('photo_consent')->nullable()->after('meal');
            });
        }

        if (! Schema::hasTable('event_volunteer_meal_option')) {
            Schema::create('event_volunteer_meal_option', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('value', 64);
                $table->string('label', 120);
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->timestamps();

                $table->unique(['event', 'value'], 'event_volunteer_meal_option_event_value_unique');
                $table->foreign('event')
                    ->references('id')
                    ->on('event')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_volunteer_meal_option');

        if (Schema::hasTable('event_volunteer_roster_detail') && Schema::hasColumn('event_volunteer_roster_detail', 'photo_consent')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->dropColumn('photo_consent');
            });
        }
    }
};
