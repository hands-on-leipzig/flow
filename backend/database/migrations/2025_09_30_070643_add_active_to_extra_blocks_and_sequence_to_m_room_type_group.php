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
        Schema::table('extra_block', function (Blueprint $table) {
            $table->boolean('active')->default(true);
        });

        Schema::table('m_room_type_group', function (Blueprint $table) {
            $table->integer('sequence')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extra_block', function (Blueprint $table) {
            $table->dropColumn('active');
        });

        Schema::table('m_room_type_group', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
