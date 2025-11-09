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
        // Add default values to name columns that are NOT NULL without defaults
        // Only modify columns that exist
        if (Schema::hasColumn('room', 'name')) {
            try {
                Schema::table('room', function (Blueprint $table) {
                    $table->string('name', 100)->default('Unnamed Room')->change();
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be modified; ignore
            }
        }
        
        if (Schema::hasColumn('team', 'name')) {
            try {
                Schema::table('team', function (Blueprint $table) {
                    $table->string('name', 100)->default('Unnamed Team')->change();
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be modified; ignore
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove default values
        Schema::table('room', function (Blueprint $table) {
            $table->string('name', 100)->default(null)->change();
        });
        
        Schema::table('team', function (Blueprint $table) {
            $table->string('name', 100)->default(null)->change();
        });
    }
};