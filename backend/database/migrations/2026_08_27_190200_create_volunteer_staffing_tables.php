<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('volunteer_person')) {
            Schema::create('volunteer_person', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('regional_partner');
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('nickname', 100)->nullable();
                $table->string('email', 255);
                $table->string('mobile', 50)->nullable();
                $table->timestamps();

                $table->unique(['regional_partner', 'email'], 'volunteer_person_rp_email_unique');
                $table->index('last_name', 'volunteer_person_last_name_index');
                $table->foreign('regional_partner')
                    ->references('id')
                    ->on('regional_partner')
                    ->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('event_volunteer_roster')) {
            Schema::create('event_volunteer_roster', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['event', 'volunteer_person'], 'event_volunteer_roster_unique');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('volunteer_person')
                    ->references('id')
                    ->on('volunteer_person')
                    ->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('event_staffing_role')) {
            Schema::create('event_staffing_role', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                // Catalog role (null = local / RP-invented)
                $table->unsignedInteger('m_role')->nullable();
                $table->string('label', 150)->nullable();
                // Snapshot from m_staffing_rule (catalog) or RP-set (local)
                $table->unsignedSmallInteger('min');
                $table->unsignedSmallInteger('best');
                $table->unsignedSmallInteger('max');
                $table->text('ui_description')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);

                $table->index('event', 'event_staffing_role_event_index');
                $table->unique(['event', 'm_role'], 'event_staffing_role_event_m_role_unique');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('m_role')->references('id')->on('m_role')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('event_staffing_group')) {
            Schema::create('event_staffing_group', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event_staffing_role');
                $table->unsignedSmallInteger('group_index')->default(1);
                $table->boolean('surplus')->default(false);

                $table->unique(
                    ['event_staffing_role', 'group_index'],
                    'event_staffing_group_role_index_unique'
                );
                $table->foreign('event_staffing_role')
                    ->references('id')
                    ->on('event_staffing_role')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('event_staffing_assignment')) {
            Schema::create('event_staffing_assignment', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event_staffing_group');
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['event_staffing_group', 'volunteer_person'],
                    'event_staffing_assignment_unique'
                );
                $table->foreign('event_staffing_group')
                    ->references('id')
                    ->on('event_staffing_group')
                    ->onDelete('cascade');
                $table->foreign('volunteer_person')
                    ->references('id')
                    ->on('volunteer_person')
                    ->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_staffing_assignment');
        Schema::dropIfExists('event_staffing_group');
        Schema::dropIfExists('event_staffing_role');
        Schema::dropIfExists('event_volunteer_roster');
        Schema::dropIfExists('volunteer_person');
    }
};
