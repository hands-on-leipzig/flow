<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event_volunteer_roster_detail', 'eve_meeting')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->dropColumn('eve_meeting');
            });
        }

        if (! Schema::hasTable('event_volunteer_field')) {
            Schema::create('event_volunteer_field', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('field_key', 64);
                $table->string('label', 120);
                $table->string('type', 20);
                $table->json('options')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->timestamps();

                $table->unique(['event', 'field_key'], 'event_volunteer_field_event_key_unique');
                $table->foreign('event')
                    ->references('id')
                    ->on('event')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('event_volunteer_field_value')) {
            Schema::create('event_volunteer_field_value', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event_volunteer_roster');
                $table->unsignedInteger('event_volunteer_field');
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(
                    ['event_volunteer_roster', 'event_volunteer_field'],
                    'event_volunteer_field_value_roster_field_unique'
                );
                $table->foreign('event_volunteer_roster')
                    ->references('id')
                    ->on('event_volunteer_roster')
                    ->onDelete('cascade');
                $table->foreign('event_volunteer_field')
                    ->references('id')
                    ->on('event_volunteer_field')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_volunteer_field_value');
        Schema::dropIfExists('event_volunteer_field');

        if (Schema::hasTable('event_volunteer_roster_detail') && ! Schema::hasColumn('event_volunteer_roster_detail', 'eve_meeting')) {
            Schema::table('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->boolean('eve_meeting')->nullable()->after('meal');
            });
        }
    }
};
