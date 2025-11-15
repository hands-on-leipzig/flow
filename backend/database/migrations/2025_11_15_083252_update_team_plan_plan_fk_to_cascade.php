<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('team_plan') || !Schema::hasColumn('team_plan', 'plan')) {
            return;
        }

        Schema::table('team_plan', function (Blueprint $table) {
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
        });

        Schema::table('team_plan', function (Blueprint $table) {
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('team_plan') || !Schema::hasColumn('team_plan', 'plan')) {
            return;
        }

        Schema::table('team_plan', function (Blueprint $table) {
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
        });

        Schema::table('team_plan', function (Blueprint $table) {
            $table->foreign('plan')->references('id')->on('plan');
        });
    }
};
