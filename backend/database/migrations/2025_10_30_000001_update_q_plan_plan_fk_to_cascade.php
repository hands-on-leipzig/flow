<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing FK on plan (if present)
        try {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->dropForeign(['plan']);
            });
        } catch (\Throwable $e) {
            // FK might not exist in some environments; ignore
        }
        
        // Try to add foreign key separately (may fail if column types don't match)
        if (Schema::hasColumn('q_plan', 'plan')) {
            try {
                Schema::table('q_plan', function (Blueprint $table) {
                    $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Ignore if foreign key can't be added (type mismatch between plan.id and q_plan.plan)
            }
        }
    }

    public function down(): void
    {
        // Drop existing FK (if present)
        try {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->dropForeign(['plan']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        // Revert to FK without cascade (RESTRICT behavior)
        if (Schema::hasColumn('q_plan', 'plan')) {
            try {
                Schema::table('q_plan', function (Blueprint $table) {
                    $table->foreign('plan')->references('id')->on('plan');
                });
            } catch (\Throwable $e) {
                // Ignore if foreign key can't be added (type mismatch)
            }
        }
    }
};


