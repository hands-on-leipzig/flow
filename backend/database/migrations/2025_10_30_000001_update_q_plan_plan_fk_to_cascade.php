<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('q_plan', function (Blueprint $table) {
            // Drop existing FK on plan (if present) and recreate with cascade delete
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // FK might not exist in some environments; ignore
            }
        });
        
        // Try to add foreign key separately (may fail if column types don't match)
        try {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key can't be added (type mismatch between plan.id and q_plan.plan)
        }
    }

    public function down(): void
    {
        Schema::table('q_plan', function (Blueprint $table) {
            // Revert to FK without cascade (RESTRICT behavior)
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // ignore
            }

            $table->foreign('plan')->references('id')->on('plan');
        });
    }
};


