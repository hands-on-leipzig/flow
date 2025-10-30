<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks to allow dropping tables with foreign key constraints
        Schema::disableForeignKeyConstraints();
        
        // Drop tables in correct order to handle foreign key constraints
        Schema::dropIfExists('q_plan_match');
        Schema::dropIfExists('q_plan_team');
        Schema::dropIfExists('q_plan');
        Schema::dropIfExists('q_run');
        
        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();
        
        // Recreate q_run table with the correct schema
        Schema::create('q_run', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('comment')->nullable();
            $table->text('selection')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('host', 100)->nullable();
            $table->integer('qplans_total')->default(0);
            $table->integer('qplans_calculated')->default(0);
        });

        // Recreate q_plan table with the correct schema
        
        Schema::create('q_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan')->nullable();
            $table->unsignedBigInteger('q_run');
            $table->string('name', 100);
            $table->integer('c_teams');
            $table->integer('r_tables');
            $table->integer('j_lanes');
            $table->integer('j_rounds');
            $table->boolean('r_asym')->default(false);
            $table->boolean('r_robot_check')->default(false);
            $table->integer('r_duration_robot_check')->default(0);
            $table->integer('c_duration_transfer');
            $table->integer('q1_ok_count')->nullable();
            $table->integer('q2_ok_count')->nullable();
            $table->integer('q3_ok_count')->nullable();
            $table->integer('q4_ok_count')->nullable();
            $table->decimal('q5_idle_avg', 8, 2)->nullable();
            $table->decimal('q5_idle_stddev', 8, 2)->nullable();
            $table->boolean('calculated')->default(false);

            $table->foreign('plan')->references('id')->on('plan');
            $table->foreign('q_run')->references('id')->on('q_run');
        });

        // Drop and recreate q_plan_team table with the correct schema
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('q_plan_team');
        Schema::enableForeignKeyConstraints();
        
        Schema::create('q_plan_team', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('q_plan');
            $table->integer('team');
            $table->boolean('q1_ok')->default(false);
            $table->decimal('q1_transition_1_2', 8, 2)->default(0);
            $table->decimal('q1_transition_2_3', 8, 2)->default(0);
            $table->decimal('q1_transition_3_4', 8, 2)->default(0);
            $table->decimal('q1_transition_4_5', 8, 2)->default(0);
            $table->boolean('q2_ok')->default(false);
            $table->integer('q2_tables')->default(0);
            $table->boolean('q3_ok')->default(false);
            $table->integer('q3_teams')->default(0);
            $table->boolean('q4_ok')->default(false);
            $table->integer('q5_idle_0_1')->default(0);
            $table->integer('q5_idle_1_2')->default(0);
            $table->integer('q5_idle_2_3')->default(0);
            $table->decimal('q5_idle_avg', 8, 2)->default(0);

            $table->foreign('q_plan')->references('id')->on('q_plan');
        });

        // Drop and recreate q_plan_match table with the correct schema
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('q_plan_match');
        Schema::enableForeignKeyConstraints();
        
        Schema::create('q_plan_match', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('q_plan');
            $table->integer('round');
            $table->integer('match_no');
            $table->integer('table_1');
            $table->integer('table_2');
            $table->integer('table_1_team');
            $table->integer('table_2_team');

            $table->foreign('q_plan')->references('id')->on('q_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks to allow dropping tables with foreign key constraints
        Schema::disableForeignKeyConstraints();
        
        // Drop tables in reverse order
        Schema::dropIfExists('q_plan_match');
        Schema::dropIfExists('q_plan_team');
        Schema::dropIfExists('q_plan');
        Schema::dropIfExists('q_run');
        
        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();
        
        // Recreate the old schema
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
};