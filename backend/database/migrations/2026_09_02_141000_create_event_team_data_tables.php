<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_team_field')) {
            Schema::create('event_team_field', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('field_key', 64);
                $table->string('label', 120);
                $table->string('type', 20);
                $table->json('options')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->boolean('public_form')->default(false);
                $table->timestamps();

                $table->unique(['event', 'field_key'], 'event_team_field_event_key_unique');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('event_team_field_value')) {
            Schema::create('event_team_field_value', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('team');
                $table->unsignedInteger('event_team_field');
                $table->text('value');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['team', 'event_team_field'], 'event_team_field_value_team_field_unique');
                $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
                $table->foreign('event_team_field')->references('id')->on('event_team_field')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('event_team_meal_count')) {
            Schema::create('event_team_meal_count', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('team');
                $table->string('meal_value', 64);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->unique(['team', 'meal_value'], 'event_team_meal_count_team_meal_unique');
                $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_team_meal_count');
        Schema::dropIfExists('event_team_field_value');
        Schema::dropIfExists('event_team_field');
    }
};
