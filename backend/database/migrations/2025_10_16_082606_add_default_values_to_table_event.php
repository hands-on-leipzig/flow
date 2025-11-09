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
        // Add default values to table_event columns that are NOT NULL without defaults
        // Only modify columns that exist
        if (Schema::hasColumn('table_event', 'name')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->string('name', 100)->default('Unnamed Table')->change();
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be modified; ignore
            }
        }
        
        if (Schema::hasColumn('table_event', 'table_name')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->string('table_name', 100)->default('Unnamed Table')->change();
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be modified; ignore
            }
        }
        
        if (Schema::hasColumn('table_event', 'table_number')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->integer('table_number')->default(1)->change();
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
        // Remove default values (only if columns exist)
        if (Schema::hasColumn('table_event', 'name')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->string('name', 100)->default(null)->change();
                });
            } catch (\Throwable $e) {
                // Ignore
            }
        }
        
        if (Schema::hasColumn('table_event', 'table_name')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->string('table_name', 100)->default(null)->change();
                });
            } catch (\Throwable $e) {
                // Ignore
            }
        }
        
        if (Schema::hasColumn('table_event', 'table_number')) {
            try {
                Schema::table('table_event', function (Blueprint $table) {
                    $table->integer('table_number')->default(null)->change();
                });
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
};