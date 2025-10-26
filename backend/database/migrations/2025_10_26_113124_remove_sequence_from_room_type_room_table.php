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
        Schema::table('room_type_room', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_type_room', function (Blueprint $table) {
            $table->integer('sequence')->default(0)->after('event');
        });
    }
};
