<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks to avoid constraint issues during table creation
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        try {
        // Create m_season table (always recreate m_ tables)
        if (Schema::hasTable('m_season')) {
            Schema::dropIfExists('m_season');
        }
        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 50);
            $table->unsignedSmallInteger('year');
        });

        // Create m_level table (always recreate m_ tables)
        if (Schema::hasTable('m_level')) {
            Schema::dropIfExists('m_level');
        }
        Schema::create('m_level', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 50);
        });

        // Create m_news table (always recreate m_ tables)
        if (Schema::hasTable('m_news')) {
            Schema::dropIfExists('m_news');
        }
        Schema::create('m_news', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('title', 255);
            $table->text('text');
            $table->string('link', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        // Create m_room_type_group table (always recreate m_ tables)
        if (Schema::hasTable('m_room_type_group')) {
            Schema::dropIfExists('m_room_type_group');
        }
        Schema::create('m_room_type_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 255)->nullable();
            $table->integer('sequence')->nullable();
        });

        // Create m_room_type table (always recreate m_ tables)
        if (Schema::hasTable('m_room_type')) {
            Schema::dropIfExists('m_room_type');
        }
        Schema::create('m_room_type', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('code', 100)->nullable()->unique();
            $table->string('name', 255)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('room_type_group');
            $table->unsignedInteger('level');
            $table->unsignedTinyInteger('first_program')->default(0);

            $table->foreign('room_type_group')->references('id')->on('m_room_type_group')->onDelete('restrict');
            $table->foreign('level')->references('id')->on('m_level')->onDelete('restrict');
        });

        // Create m_first_program table (always recreate m_ tables)
        if (Schema::hasTable('m_first_program')) {
            Schema::dropIfExists('m_first_program');
        }
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 50);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex', 10)->nullable();
            $table->string('logo_white', 255)->nullable();
        });

        // Create m_parameter table (always recreate m_ tables)
        if (Schema::hasTable('m_parameter')) {
            Schema::dropIfExists('m_parameter');
        }
        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 255)->nullable()->unique();
            $table->enum('context', ['input', 'expert', 'protected', 'finale'])->nullable();
            $table->unsignedInteger('level');
            $table->enum('type', ['integer', 'decimal', 'time', 'date', 'boolean'])->nullable();
            $table->string('value', 255)->nullable();
            $table->string('min', 255)->nullable();
            $table->string('max', 255)->nullable();
            $table->string('step', 255)->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('ui_label', 255)->nullable();
            $table->longText('ui_description')->nullable();

            $table->foreign('level')->references('id')->on('m_level')->onDelete('restrict');
            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });

        // Create m_parameter_condition table (always recreate m_ tables)
        if (Schema::hasTable('m_parameter_condition')) {
            Schema::dropIfExists('m_parameter_condition');
        }
        Schema::create('m_parameter_condition', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger("parameter")->nullable();
            $table->foreign("parameter")->references("id")->on("m_parameter")->onDelete('cascade');
            $table->unsignedInteger("if_parameter")->nullable();
            $table->foreign("if_parameter")->references("id")->on("m_parameter")->onDelete('cascade');
            $table->enum("is", ["=", "<", ">"])->nullable();
            $table->string("value")->nullable();
            $table->enum("action", ["show", "hide", "disable"])->default('show');
        });

        // Create m_activity_type table (always recreate m_ tables)
        if (Schema::hasTable('m_activity_type')) {
            Schema::dropIfExists('m_activity_type');
        }
        Schema::create('m_activity_type', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->text('description')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->string('overview_plan_column', 100)->nullable();

            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });

        // Create m_activity_type_detail table (always recreate m_ tables)
        if (Schema::hasTable('m_activity_type_detail')) {
            Schema::dropIfExists('m_activity_type_detail');
        }
        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->string('code', 50)->nullable();
            $table->string('name_preview', 100)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
            $table->text('description')->nullable();
            $table->string('link', 255)->nullable();
            $table->string('link_text', 100)->nullable();
            $table->unsignedInteger('activity_type');

            $table->foreign('activity_type')->references('id')->on('m_activity_type')->onDelete('restrict');
            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });

        // Create m_insert_point table (always recreate m_ tables)
        if (Schema::hasTable('m_insert_point')) {
            Schema::dropIfExists('m_insert_point');
        }
        Schema::create('m_insert_point', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('code', 50)->nullable()->unique();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('ui_label', 255)->nullable();
            $table->text('ui_description')->nullable();

            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
            $table->foreign('level')->references('id')->on('m_level')->onDelete('restrict');
        });

        // Create m_role table (always recreate m_ tables)
        if (Schema::hasTable('m_role')) {
            Schema::dropIfExists('m_role');
        }
        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->string('name_short', 50)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
            $table->text('description')->nullable();
            $table->string('differentiation_type', 100)->nullable();
            $table->text('differentiation_source')->nullable();
            $table->string('differentiation_parameter', 100)->nullable();
            $table->boolean('preview_matrix')->default(false);
            $table->boolean('pdf_export')->default(false);

            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });

        // Create m_visibility table (always recreate m_ tables)
        if (Schema::hasTable('m_visibility')) {
            Schema::dropIfExists('m_visibility');
        }
        Schema::create('m_visibility', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('activity_type_detail')->nullable();
            $table->unsignedInteger('role')->nullable();

            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail')->onDelete('cascade');
            $table->foreign('role')->references('id')->on('m_role')->onDelete('cascade');
        });

        // Create m_supported_plan table (always recreate m_ tables)
        if (Schema::hasTable('m_supported_plan')) {
            Schema::dropIfExists('m_supported_plan');
        }
        Schema::create('m_supported_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedSmallInteger('teams')->nullable();
            $table->unsignedSmallInteger('lanes')->nullable();
            $table->unsignedSmallInteger('tables')->nullable();
            $table->boolean('calibration')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('alert_level')->nullable();

            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });

        // Create regional_partner table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('regional_partner')) {
        Schema::create('regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->string('region', 100);
            $table->integer('dolibarr_id')->nullable();
        });
        }

        // Create event table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('event')) {
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100)->nullable();
            $table->string('slug', 255)->nullable();
            $table->unsignedSmallInteger('event_explore')->nullable();
            $table->unsignedSmallInteger('event_challenge')->nullable();
            $table->unsignedInteger('contao_id_explore')->nullable();
            $table->unsignedInteger('contao_id_challenge')->nullable();
            $table->unsignedInteger('regional_partner');
            $table->unsignedInteger('level');
            $table->unsignedInteger('season');
            $table->date('date');
            $table->unsignedTinyInteger('days');
            $table->string('link', 255)->nullable();
            $table->longText('qrcode')->nullable();
            $table->string('wifi_ssid', 255)->nullable();
            $table->longText('wifi_password')->nullable();
            $table->text('wifi_instruction')->nullable();
            $table->longText('wifi_qrcode')->nullable();

            $table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('restrict');
            $table->foreign('level')->references('id')->on('m_level')->onDelete('restrict');
            $table->foreign('season')->references('id')->on('m_season')->onDelete('restrict');
        });

        // Create contao_public_rounds table
        if (!Schema::hasTable('contao_public_rounds')) {
            Schema::create('contao_public_rounds', function (Blueprint $table) {
                $table->unsignedInteger('event_id')->primary();
                $table->boolean('vr1')->default(true);
                $table->boolean('vr2')->default(false);
                $table->boolean('vr3')->default(false);
                $table->boolean('vf')->default(false);
                $table->boolean('hf')->default(false);

                $table->foreign('event_id')->references('id')->on('event')->onDelete('cascade');
            });
        }
        }

        // Create slideshow table (only if it doesn't exist - preserve data)
        // Note: If table exists, we skip it entirely to avoid foreign key conflicts
        if (!Schema::hasTable('slideshow')) {
            try {
                Schema::create('slideshow', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->string('name')->nullable(true);
                    $table->unsignedInteger('event');
                    $table->integer('transition_time')->default(15);
                    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen in race conditions or if table structure differs
            }
        }

        // Create slide table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('slide')) {
        Schema::create('slide', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 255);
            $table->string('type', 255);
            $table->longText('content');
            $table->integer('order')->default(0);
            $table->unsignedInteger('slideshow_id');
            $table->boolean('active')->default(true);

            $table->foreign('slideshow_id')->references('id')->on('slideshow')->onDelete('cascade');
        });
        }

        // Create publication table (only if it doesn't exist - preserve data)
        // Note: If table exists, we skip it entirely to avoid foreign key conflicts
        if (!Schema::hasTable('publication')) {
            try {
                Schema::create('publication', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->unsignedInteger('event');
                    $table->unsignedInteger('level');
                    $table->timestamp('last_change');

                    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen in race conditions or if table structure differs
            }
        }

        // Create user table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('user')) {
        Schema::create('user', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('nick', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->integer('dolibarr_id')->nullable();
            $table->string('lang', 10)->nullable();
            $table->timestamp('last_login')->nullable();
            $table->unsignedInteger('selection_regional_partner')->nullable();
            $table->unsignedInteger('selection_event')->nullable();

            $table->foreign('selection_regional_partner')->references('id')->on('regional_partner')->onDelete('set null');
            $table->foreign('selection_event')->references('id')->on('event')->onDelete('set null');
        });
        }

        // Create news_user table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('news_user')) {
        Schema::create('news_user', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('news_id');
            $table->timestamp('read_at')->useCurrent();

            $table->unique(['user_id', 'news_id'], 'news_user_user_id_news_id_unique');
            $table->foreign('user_id')->references('id')->on('user')->onDelete('cascade');
            $table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
        });
        }

        // Create user_regional_partner table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('user_regional_partner')) {
        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('regional_partner');

            $table->foreign('user')->references('id')->on('user')->onDelete('cascade');
            $table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('cascade');
        });
        }

        // Create room table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('room')) {
        Schema::create('room', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->text('navigation_instruction')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_accessible')->default(true);

            $table->index(['event', 'sequence'], 'room_event_sequence_index');
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
        }

        // Create room_type_room table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('room_type_room')) {
        Schema::create('room_type_room', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('room_type');
            $table->unsignedInteger('room');
            $table->unsignedInteger('event');

            $table->index('room_type');
            $table->index('room');
            $table->index('event');

            $table->foreign('room_type')->references('id')->on('m_room_type')->onDelete('cascade');
            $table->foreign('room')->references('id')->on('room')->onDelete('cascade');
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
        }

        // Create team table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('team')) {
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot');
            $table->string('location', 255)->nullable();
            $table->string('organization', 255)->nullable();

            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
        });
        }

        // Create plan table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('plan')) {
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->timestamp('created')->nullable();
            $table->timestamp('last_change')->nullable();
            $table->string('generator_status', 50)->nullable();

            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
        }

        // Create s_generator table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('s_generator')) {
        Schema::create('s_generator', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('plan');
            $table->timestamp('start')->nullable();
            $table->timestamp('end')->nullable();
            $table->string('mode', 255)->nullable();

            $table->index('plan');
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
        }

        // Create s_one_link_access table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('s_one_link_access')) {
        Schema::create('s_one_link_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->date('access_date');
            $table->timestamp('access_time')->nullable();
            
            // Server-side captured (from HTTP request)
            $table->text('user_agent')->nullable();
            $table->text('referrer')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('accept_language', 50)->nullable();
            
            // Client-side captured (sent from frontend)
            $table->unsignedSmallInteger('screen_width')->nullable();
            $table->unsignedSmallInteger('screen_height')->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->decimal('device_pixel_ratio', 3, 2)->nullable();
            $table->boolean('touch_support')->nullable();
            $table->string('connection_type', 20)->nullable();
            
            // Source tracking
            $table->string('source', 20)->nullable();
            
            // Indexes
            $table->index(['event', 'access_date'], 'idx_event_access_date');
            $table->index('access_date', 'idx_access_date');
            
            // Foreign key
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
        }

        // Create team_plan table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('team_plan')) {
        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->integer('team_number_plan');
            $table->unsignedInteger('room')->nullable();
            $table->boolean('noshow')->default(false);

            $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            $table->foreign('room')->references('id')->on('room')->onDelete('set null');
        });
        }

        // Create plan_param_value table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('plan_param_value')) {
        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value', 255)->nullable();

            $table->unique(['plan', 'parameter']);
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            $table->foreign('parameter')->references('id')->on('m_parameter')->onDelete('cascade');
        });
        }

        // Create match table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('match')) {
        Schema::create('match', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('round');
            $table->unsignedInteger('match_no');
            $table->unsignedInteger('table_1');
            $table->unsignedInteger('table_2');
            $table->unsignedInteger('table_1_team');
            $table->unsignedInteger('table_2_team');

            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
        }

        // Create extra_block table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('extra_block')) {
        Schema::create('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('link', 255)->nullable();
            $table->unsignedInteger('insert_point')->nullable();
            $table->unsignedInteger('buffer_before')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('buffer_after')->nullable();
            $table->datetime('start')->nullable();
            $table->datetime('end')->nullable();
            $table->unsignedInteger('room')->nullable();
            $table->boolean('active')->default(false);

            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            $table->foreign('insert_point')->references('id')->on('m_insert_point')->onDelete('cascade');
            $table->foreign('room')->references('id')->on('room')->nullOnDelete();
        });
        }

        // Create activity_group table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('activity_group')) {
        Schema::create('activity_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('plan');

            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail')->onDelete('cascade');
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
        }

        // Create activity table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('activity')) {
        Schema::create('activity', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('activity_group');
            $table->datetime('start');
            $table->datetime('end');
            $table->unsignedInteger('room_type')->nullable();
            $table->unsignedTinyInteger('jury_lane')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedTinyInteger('table_1')->nullable();
            $table->unsignedInteger('table_1_team')->nullable();
            $table->unsignedTinyInteger('table_2')->nullable();
            $table->unsignedInteger('table_2_team')->nullable();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('extra_block')->nullable();

            $table->foreign('activity_group')->references('id')->on('activity_group')->onDelete('cascade');
            $table->foreign('room_type')->references('id')->on('m_room_type')->onDelete('cascade');
            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail')->onDelete('cascade');
            $table->foreign('extra_block')->references('id')->on('extra_block')->nullOnDelete();
        });
        }

        // Create logo table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('logo')) {
        Schema::create('logo', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('title', 100)->nullable();
            $table->string('link', 500)->nullable();
            $table->string('path', 255);
            $table->unsignedInteger('regional_partner');

            $table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('cascade');
        });
        }

        // Create event_logo table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('event_logo')) {
        Schema::create('event_logo', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('logo');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['event', 'logo'], 'just_one');
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            $table->foreign('logo')->references('id')->on('logo')->onDelete('cascade');
        });
        }

        // Create table_event table (only if it doesn't exist - preserve data)
        if (!Schema::hasTable('table_event')) {
        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('table_name', 100);
            $table->integer('table_number');
            $table->unsignedInteger('event');

            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
        }

        // Create q_plan table (only if it doesn't exist - preserve data)
        // Note: If table exists, we skip it entirely to avoid foreign key conflicts
        if (!Schema::hasTable('q_plan')) {
            try {
                Schema::create('q_plan', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->unsignedInteger('plan');
                    $table->unsignedInteger('q_run')->nullable();
                    $table->string('name', 100);
                    $table->timestamp('last_change')->nullable();
                    $table->unsignedInteger('c_teams');
                    $table->unsignedInteger('r_tables');
                    $table->unsignedInteger('j_lanes');
                    $table->unsignedInteger('j_rounds');
                    $table->boolean('r_asym')->default(false);
                    $table->boolean('r_robot_check')->default(false);
                    $table->unsignedInteger('r_duration_robot_check')->default(0);
                    $table->unsignedInteger('c_duration_transfer');
                    $table->unsignedInteger('q1_ok_count')->nullable();
                    $table->unsignedInteger('q2_ok_count')->nullable();
                    $table->unsignedInteger('q2_1_count')->nullable();
                    $table->unsignedInteger('q2_2_count')->nullable();
                    $table->unsignedInteger('q2_3_count')->nullable();
                    $table->decimal('q2_score_avg', 5, 2)->nullable();
                    $table->unsignedInteger('q3_ok_count')->nullable();
                    $table->unsignedInteger('q3_1_count')->nullable();
                    $table->unsignedInteger('q3_2_count')->nullable();
                    $table->unsignedInteger('q3_3_count')->nullable();
                    $table->decimal('q3_score_avg', 5, 2)->nullable();
                    $table->unsignedInteger('q4_ok_count')->nullable();
                    $table->decimal('q5_idle_avg', 8, 2)->nullable();
                    $table->decimal('q5_idle_stddev', 8, 2)->nullable();
                    $table->unsignedInteger('q6_duration')->nullable();
                    $table->boolean('calculated')->default(false);

                    $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
                    $table->foreign('q_run')->references('id')->on('q_run')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen in race conditions or if table structure differs
            }
        }

        // Create q_plan_team table (only if it doesn't exist - preserve data)
        // Note: If table exists, we skip it entirely to avoid foreign key conflicts
        if (!Schema::hasTable('q_plan_team')) {
            try {
                Schema::create('q_plan_team', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->unsignedInteger('q_plan');
                    $table->unsignedInteger('team');
                    $table->boolean('q1_ok')->default(false);
                    $table->decimal('q1_transition_1_2', 8, 2)->default(0);
                    $table->decimal('q1_transition_2_3', 8, 2)->default(0);
                    $table->decimal('q1_transition_3_4', 8, 2)->default(0);
                    $table->decimal('q1_transition_4_5', 8, 2)->default(0);
                    $table->boolean('q2_ok')->default(false);
                    $table->unsignedInteger('q2_tables')->default(0);
                    $table->boolean('q3_ok')->default(false);
                    $table->unsignedInteger('q3_teams')->default(0);
                    $table->boolean('q4_ok')->default(false);
                    $table->unsignedInteger('q5_idle_0_1')->default(0);
                    $table->unsignedInteger('q5_idle_1_2')->default(0);
                    $table->unsignedInteger('q5_idle_2_3')->default(0);
                    $table->decimal('q5_idle_avg', 8, 2)->default(0);

                    $table->foreign('q_plan')->references('id')->on('q_plan')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen in race conditions or if table structure differs
            }
        }

        // Create q_run table (only if it doesn't exist - preserve data)
        // Note: If table exists, we skip it entirely to avoid foreign key conflicts
        if (!Schema::hasTable('q_run')) {
            try {
                Schema::create('q_run', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->string('name', 100);
                    $table->text('comment')->nullable();
                    $table->text('selection')->nullable();
                    $table->timestamp('started_at')->nullable();
                    $table->timestamp('finished_at')->nullable();
                    $table->string('status', 20)->default('pending');
                    $table->string('host', 100)->nullable();
                    $table->unsignedInteger('qplans_total')->default(0);
                    $table->unsignedInteger('qplans_calculated')->default(0);
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen in race conditions or if table structure differs
            }
        }
        } finally {
            // Re-enable foreign key checks
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tables in reverse order to handle foreign key constraints
        Schema::dropIfExists('q_run');
        Schema::dropIfExists('q_plan_team');
        Schema::dropIfExists('q_plan');
        Schema::dropIfExists('table_event');
        Schema::dropIfExists('event_logo');
        Schema::dropIfExists('logo');
        Schema::dropIfExists('activity_group');
        Schema::dropIfExists('activity');
        Schema::dropIfExists('extra_block');
        Schema::dropIfExists('match');
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
        Schema::dropIfExists('news_user');
        Schema::dropIfExists('user');
        Schema::dropIfExists('contao_public_rounds');
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
        Schema::dropIfExists('m_news');
        Schema::dropIfExists('m_room_type');
        Schema::dropIfExists('m_room_type_group');
        Schema::dropIfExists('m_level');
        Schema::dropIfExists('m_season');
    }
};
