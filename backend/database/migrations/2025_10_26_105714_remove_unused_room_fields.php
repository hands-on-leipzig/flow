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
        // Remove unused room.room_type field
        Schema::table('room', function (Blueprint $table) {
            $table->dropForeign(['room_type']);
            $table->dropColumn('room_type');
        });

        // Remove unused team.room field
        Schema::table('team', function (Blueprint $table) {
            $table->dropForeign(['room']);
            $table->dropColumn('room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore room.room_type field
        Schema::table('room', function (Blueprint $table) {
            $table->unsignedBigInteger('room_type')->nullable();
            $table->foreign('room_type')->references('id')->on('m_room_type');
        });

        // Restore team.room field
        Schema::table('team', function (Blueprint $table) {
            $table->unsignedBigInteger('room')->nullable();
            $table->foreign('room')->references('id')->on('room')->onDelete('set null');
        });
    }
};
