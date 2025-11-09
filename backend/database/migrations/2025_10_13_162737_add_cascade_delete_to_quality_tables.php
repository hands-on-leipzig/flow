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
        // Drop existing foreign key constraints (may not exist)
        try {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->dropForeign(['q_run']);
            });
        } catch (\Throwable $e) {
            // Foreign key might not exist; ignore
        }
        
        try {
            Schema::table('q_plan_team', function (Blueprint $table) {
                $table->dropForeign(['q_plan']);
            });
        } catch (\Throwable $e) {
            // Foreign key might not exist; ignore
        }
        
        try {
            Schema::table('q_plan_match', function (Blueprint $table) {
                $table->dropForeign(['q_plan']);
            });
        } catch (\Throwable $e) {
            // Foreign key might not exist; ignore
        }

        // Recreate foreign key constraints with CASCADE DELETE
        // Try each one separately and ignore errors if types don't match
        try {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->foreign('q_run')->references('id')->on('q_run')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key can't be added (type mismatch)
        }
        
        try {
            Schema::table('q_plan_team', function (Blueprint $table) {
                $table->foreign('q_plan')->references('id')->on('q_plan')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key can't be added (type mismatch)
        }
        
        try {
            Schema::table('q_plan_match', function (Blueprint $table) {
                $table->foreign('q_plan')->references('id')->on('q_plan')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key can't be added (type mismatch)
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop CASCADE foreign key constraints
        Schema::table('q_plan', function (Blueprint $table) {
            $table->dropForeign(['q_run']);
        });
        
        Schema::table('q_plan_team', function (Blueprint $table) {
            $table->dropForeign(['q_plan']);
        });
        
        Schema::table('q_plan_match', function (Blueprint $table) {
            $table->dropForeign(['q_plan']);
        });

        // Recreate original RESTRICT foreign key constraints
        Schema::table('q_plan', function (Blueprint $table) {
            $table->foreign('q_run')->references('id')->on('q_run');
        });
        
        Schema::table('q_plan_team', function (Blueprint $table) {
            $table->foreign('q_plan')->references('id')->on('q_plan');
        });
        
        Schema::table('q_plan_match', function (Blueprint $table) {
            $table->foreign('q_plan')->references('id')->on('q_plan');
        });
    }
};
