<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create m_season table
        Schema::create('m_season', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedSmallInteger('year');
        });

        // Create m_level table
        Schema::create('m_level', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
        });

        // Create m_room_type_group table
        Schema::create('m_room_type_group', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->integer('sequence')->nullable();
        });

        // Create m_room_type table
        Schema::create('m_room_type', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->nullable()->unique();
            $table->string('name', 255)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedBigInteger('room_type_group')->nullable();
            $table->unsignedBigInteger('level')->nullable();
            $table->unsignedTinyInteger('first_program')->default(0);

            $table->foreign('room_type_group')->references('id')->on('m_room_type_group');
            $table->foreign('level')->references('id')->on('m_level');
        });

        // Create m_first_program table
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex', 10)->nullable();
            $table->string('logo_white', 255)->nullable();
        });

        // Create m_parameter table
        Schema::create('m_parameter', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->enum('context', ['input', 'expert', 'protected', 'finale'])->nullable();
            $table->unsignedBigInteger('level');
            $table->enum('type', ['integer', 'decimal', 'time', 'date', 'boolean'])->nullable();
            $table->string('value', 255)->nullable();
            $table->string('min', 255)->nullable();
            $table->string('max', 255)->nullable();
            $table->string('step', 255)->nullable();
            $table->unsignedBigInteger('first_program')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('ui_label', 255)->nullable();
            $table->longText('ui_description')->nullable();

            $table->foreign('level')->references('id')->on('m_level');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create m_parameter_condition table
        Schema::create('m_parameter_condition', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("parameter")->nullable();
            $table->foreign("parameter")->references("id")->on("m_parameter")->nullOnDelete();
            $table->unsignedBigInteger("if_parameter")->nullable();
            $table->foreign("if_parameter")->references("id")->on("m_parameter")->nullOnDelete();
            $table->enum("is", ["=", "<", ">"])->nullable();
            $table->string("value")->nullable();
            $table->enum("action", ["hide", "show"])->nullable();
        });

        // Create m_activity_type table
        Schema::create('m_activity_type', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('first_program')->nullable();
            $table->string('overview_plan_column', 100)->nullable();

            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create m_activity_type_detail table
        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 50)->nullable();
            $table->string('name_preview', 100)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedBigInteger('first_program')->nullable();
            $table->text('description')->nullable();
            $table->string('link', 255)->nullable();
            $table->string('link_text', 100)->nullable();
            $table->unsignedBigInteger('activity_type');

            $table->foreign('activity_type')->references('id')->on('m_activity_type');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create m_insert_point table
        Schema::create('m_insert_point', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('first_program')->nullable();
            $table->unsignedBigInteger('level')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('ui_label', 255)->nullable();
            $table->text('ui_description')->nullable();
            $table->string('room_type', 100)->nullable();

            $table->foreign('first_program')->references('id')->on('m_first_program');
            $table->foreign('level')->references('id')->on('m_level');
        });

        // Create m_role table
        Schema::create('m_role', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('name_short', 50)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedBigInteger('first_program')->nullable();
            $table->text('description')->nullable();
            $table->string('differentiation_type', 100)->nullable();
            $table->text('differentiation_source')->nullable();
            $table->string('differentiation_parameter', 100)->nullable();
            $table->boolean('preview_matrix')->default(false);
            $table->boolean('pdf_export')->default(false);

            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create m_visibility table
        Schema::create('m_visibility', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_type_detail')->nullable();
            $table->unsignedBigInteger('role')->nullable();

            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail');
            $table->foreign('role')->references('id')->on('m_role');
        });

        // Create m_supported_plan table
        Schema::create('m_supported_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('first_program')->nullable();
            $table->unsignedSmallInteger('teams')->nullable();
            $table->unsignedSmallInteger('lanes')->nullable();
            $table->unsignedSmallInteger('tables')->nullable();
            $table->boolean('calibration')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('alert_level')->nullable();
            $table->boolean('suggested')->default(false);

            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create regional_partner table
        Schema::create('regional_partner', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('region', 100)->nullable();
            $table->integer('dolibarr_id')->nullable();
        });

        // Create event table
        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('slug', 255)->nullable();
            $table->unsignedSmallInteger('event_explore')->nullable();
            $table->unsignedSmallInteger('event_challenge')->nullable();
            $table->unsignedBigInteger('regional_partner')->nullable();
            $table->unsignedBigInteger('level');
            $table->unsignedBigInteger('season');
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('days')->nullable();
            $table->string('link', 255)->nullable();
            $table->longText('qrcode')->nullable();
            $table->string('wifi_ssid', 255)->nullable();
            $table->longText('wifi_password')->nullable();
            $table->text('wifi_instruction')->nullable();
            $table->longText('wifi_qrcode')->nullable();

            $table->foreign('regional_partner')->references('id')->on('regional_partner')->nullOnDelete();
            $table->foreign('level')->references('id')->on('m_level');
            $table->foreign('season')->references('id')->on('m_season');
        });

        // Create slideshow table
        Schema::create('slideshow', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(true);
            $table->unsignedBigInteger('event');
            $table->integer('transition_time')->default(15);
            $table->foreign('event')->references('id')->on('event');
        });

        // Create slide table
        Schema::create('slide', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->json('content');
            $table->integer('order')->default(0);
            $table->unsignedBigInteger('slideshow');
            $table->foreign('slideshow')->references('id')->on('slideshow')->onDelete('cascade');
        });

        // Create publication table
        Schema::create('publication', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->integer('level');
            $table->timestamps();

            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });

        // Create user table
        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->string('nick', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->integer('dolibarr_id')->nullable();
            $table->string('lang', 10)->nullable();
            $table->timestamp('last_login')->nullable();
            $table->unsignedBigInteger('selection_regional_partner')->nullable();
            $table->unsignedBigInteger('selection_event')->nullable();

            $table->foreign('selection_regional_partner')->references('id')->on('regional_partner');
            $table->foreign('selection_event')->references('id')->on('event');
        });

        // Create user_regional_partner table
        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user');
            $table->unsignedBigInteger('regional_partner');

            $table->foreign('user')->references('id')->on('user');
            $table->foreign('regional_partner')->references('id')->on('regional_partner');
        });

        // Create room table
        Schema::create('room', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('room_type');
            $table->unsignedBigInteger('event');
            $table->text('navigation_instructions')->nullable();

            $table->foreign('room_type')->references('id')->on('m_room_type');
            $table->foreign('event')->references('id')->on('event');
        });

        // Create room_type_room table
        Schema::create('room_type_room', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_type');
            $table->unsignedBigInteger('room');
            $table->unsignedBigInteger('event');

            $table->foreign('room_type')->references('id')->on('m_room_type');
            $table->foreign('room')->references('id')->on('room');
            $table->foreign('event')->references('id')->on('event');
        });

        // Create team table
        Schema::create('team', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('room')->nullable();
            $table->unsignedBigInteger('first_program');
            $table->integer('team_number_hot')->nullable();
            $table->boolean('noshow')->default(false);
            $table->string('location', 255)->nullable();

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('room')->references('id')->on('room');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create plan table
        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('level');
            $table->unsignedBigInteger('first_program');
            $table->timestamp('created')->nullable();
            $table->timestamp('last_change')->nullable();
            $table->boolean('public')->default(false);
            $table->string('generator_status', 50)->nullable();

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('level')->references('id')->on('m_level');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });

        // Create team_plan table
        Schema::create('team_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team');
            $table->unsignedBigInteger('plan');
            $table->integer('team_number_plan')->nullable();
            $table->unsignedBigInteger('room')->nullable();

            $table->foreign('team')->references('id')->on('team');
            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('room')->references('id')->on('room');
        });

        // Create plan_param_value table
        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedBigInteger('parameter');
            $table->string('value', 255)->nullable();
            $table->string('set_value', 255)->nullable();

            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('parameter')->references('id')->on('m_parameter');
        });

        // Create extra_block table
        Schema::create('extra_block', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedBigInteger('first_program')->nullable();
            $table->string('name', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('link', 255)->nullable();
            $table->integer('insert_point')->nullable();
            $table->integer('buffer_before')->nullable();
            $table->integer('duration')->nullable();
            $table->integer('buffer_after')->nullable();
            $table->datetime('start')->nullable();
            $table->datetime('end')->nullable();
            $table->unsignedBigInteger('room')->nullable();
            $table->boolean('active')->default(true);

            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('room')->references('id')->on('room');
        });

        // Create plan_extra_block table
        Schema::create('plan_extra_block', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedBigInteger('extra_block');

            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('extra_block')->references('id')->on('extra_block');
        });

        // Create activity_group table
        Schema::create('activity_group', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('plan');
            $table->unsignedBigInteger('activity_type_detail')->nullable();

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail');
        });

        // Create activity table
        Schema::create('activity', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('activity_type');
            $table->unsignedBigInteger('activity_type_detail')->nullable();
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('room')->nullable();
            $table->unsignedBigInteger('room_type')->nullable();
            $table->unsignedBigInteger('activity_group')->nullable();
            $table->unsignedBigInteger('extra_block')->nullable();
            $table->datetime('start');
            $table->datetime('end');
            $table->integer('jury_lane')->nullable();
            $table->integer('jury_team')->nullable();
            $table->integer('table_1')->nullable();
            $table->integer('table_1_team')->nullable();
            $table->integer('table_2')->nullable();
            $table->integer('table_2_team')->nullable();

            $table->foreign('activity_type')->references('id')->on('m_activity_type');
            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail');
            $table->foreign('event')->references('id')->on('event');
            $table->foreign('room')->references('id')->on('room');
            $table->foreign('room_type')->references('id')->on('m_room_type');
            $table->foreign('activity_group')->references('id')->on('activity_group');
            $table->foreign('extra_block')->references('id')->on('extra_block');
        });

        // Create logo table
        Schema::create('logo', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('filename', 255);
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('regional_partner')->nullable();

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('regional_partner')->references('id')->on('regional_partner');
        });

        // Create event_logo table
        Schema::create('event_logo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('logo');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('logo')->references('id')->on('logo');
        });

        // Create table_event table
        Schema::create('table_event', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('table_name', 100);
            $table->integer('table_number');
            $table->unsignedBigInteger('event');

            $table->foreign('event')->references('id')->on('event');
        });

        // Create q_plan table
        Schema::create('q_plan', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('event');
            $table->unsignedBigInteger('level');

            $table->foreign('event')->references('id')->on('event');
            $table->foreign('level')->references('id')->on('m_level');
        });

        // Create q_plan_match table
        Schema::create('q_plan_match', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('q_plan');
            $table->unsignedBigInteger('team');

            $table->foreign('q_plan')->references('id')->on('q_plan');
            $table->foreign('team')->references('id')->on('team');
        });

        // Create q_plan_team table
        Schema::create('q_plan_team', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('q_plan');
            $table->unsignedBigInteger('team');

            $table->foreign('q_plan')->references('id')->on('q_plan');
            $table->foreign('team')->references('id')->on('team');
        });

        // Create q_run table
        Schema::create('q_run', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('q_plan');
            $table->unsignedBigInteger('team');
            $table->datetime('start');
            $table->datetime('end');

            $table->foreign('q_plan')->references('id')->on('q_plan');
            $table->foreign('team')->references('id')->on('team');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tables in reverse order to handle foreign key constraints
        Schema::dropIfExists('q_run');
        Schema::dropIfExists('q_plan_team');
        Schema::dropIfExists('q_plan_match');
        Schema::dropIfExists('q_plan');
        Schema::dropIfExists('table_event');
        Schema::dropIfExists('event_logo');
        Schema::dropIfExists('logo');
        Schema::dropIfExists('activity_group');
        Schema::dropIfExists('activity');
        Schema::dropIfExists('plan_extra_block');
        Schema::dropIfExists('extra_block');
        Schema::dropIfExists('plan_param_value');
        Schema::dropIfExists('plan');
        Schema::dropIfExists('team_plan');
        Schema::dropIfExists('team');
        Schema::dropIfExists('room_type_room');
        Schema::dropIfExists('room');
        Schema::dropIfExists('publication');
        Schema::dropIfExists('slide');
        Schema::dropIfExists('slideshow');
        Schema::dropIfExists('user_regional_partner');
        Schema::dropIfExists('user');
        Schema::dropIfExists('event');
        Schema::dropIfExists('regional_partner');
        Schema::dropIfExists('m_supported_plan');
        Schema::dropIfExists('m_visibility');
        Schema::dropIfExists('m_role');
        Schema::dropIfExists('m_insert_point');
        Schema::dropIfExists('m_activity_type_detail');
        Schema::dropIfExists('m_activity_type');
        Schema::dropIfExists('m_parameter');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_room_type');
        Schema::dropIfExists('m_room_type_group');
        Schema::dropIfExists('m_level');
        Schema::dropIfExists('m_season');
    }
};
