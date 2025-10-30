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

            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
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


