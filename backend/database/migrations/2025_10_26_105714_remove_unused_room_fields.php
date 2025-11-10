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
        // Remove unused room.room_type field (if exists)
        if (Schema::hasColumn('room', 'room_type')) {
            // Drop foreign key first (if exists)
            try {
                Schema::table('room', function (Blueprint $table) {
                    $table->dropForeign(['room_type']);
                });
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
            
            // Then drop column
            try {
                Schema::table('room', function (Blueprint $table) {
                    $table->dropColumn('room_type');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be dropped; ignore
            }
        }

        // Remove unused team.room field (if exists)
        if (Schema::hasColumn('team', 'room')) {
            // Drop foreign key first (if exists)
            try {
                Schema::table('team', function (Blueprint $table) {
                    $table->dropForeign(['room']);
                });
            } catch (\Throwable $e) {
                // Foreign key might not exist; ignore
            }
            
            // Then drop column
            try {
                Schema::table('team', function (Blueprint $table) {
                    $table->dropColumn('room');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be dropped; ignore
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore room.room_type field
        Schema::table('room', function (Blueprint $table) {
            $table->unsignedInteger('room_type')->nullable();
            $table->foreign('room_type')->references('id')->on('m_room_type');
        });

        // Restore team.room field
        Schema::table('team', function (Blueprint $table) {
            $table->unsignedInteger('room')->nullable();
            $table->foreign('room')->references('id')->on('room')->onDelete('set null');
        });
    }
};
