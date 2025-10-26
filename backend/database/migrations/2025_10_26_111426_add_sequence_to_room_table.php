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
        Schema::table('room', function (Blueprint $table) {
            $table->integer('sequence')->default(0)->after('navigation_instruction');
            $table->index(['event', 'sequence'], 'room_event_sequence_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room', function (Blueprint $table) {
            $table->dropIndex('room_event_sequence_index');
            $table->dropColumn('sequence');
        });
    }
};
