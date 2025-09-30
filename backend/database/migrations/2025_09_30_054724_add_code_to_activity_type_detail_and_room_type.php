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
        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->string('code', 100)->nullable()->after('id')->unique();
        });

        Schema::table('m_room_type', function (Blueprint $table) {
            $table->string('code', 100)->nullable()->after('id')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('m_room_type', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};