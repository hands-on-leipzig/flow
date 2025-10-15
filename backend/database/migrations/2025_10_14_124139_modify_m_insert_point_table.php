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
        Schema::table('m_insert_point', function (Blueprint $table) {
            // Add code column after id
            $table->string('code', 50)->nullable()->after('id')->unique();
            
            // Remove room_type column
            $table->dropColumn('room_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_insert_point', function (Blueprint $table) {
            // Remove code column
            $table->dropUnique(['code']);
            $table->dropColumn('code');
            
            // Add back room_type column
            $table->string('room_type', 100)->nullable();
        });
    }
};
