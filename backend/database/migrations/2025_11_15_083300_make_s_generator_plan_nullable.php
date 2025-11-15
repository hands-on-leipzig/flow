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
        if (!Schema::hasTable('s_generator') || !Schema::hasColumn('s_generator', 'plan')) {
            return;
        }

        Schema::table('s_generator', function (Blueprint $table) {
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
        });

        Schema::table('s_generator', function (Blueprint $table) {
            $table->unsignedInteger('plan')->nullable()->change();
        });

        Schema::table('s_generator', function (Blueprint $table) {
            $table->foreign('plan')->references('id')->on('plan')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('s_generator') || !Schema::hasColumn('s_generator', 'plan')) {
            return;
        }

        Schema::table('s_generator', function (Blueprint $table) {
            try {
                $table->dropForeign(['plan']);
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
        });

        Schema::table('s_generator', function (Blueprint $table) {
            $table->unsignedInteger('plan')->nullable(false)->change();
        });

        Schema::table('s_generator', function (Blueprint $table) {
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
        });
    }
};
