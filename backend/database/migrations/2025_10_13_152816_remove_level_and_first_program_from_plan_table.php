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
        Schema::table('plan', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['level']);
            $table->dropForeign(['first_program']);
            
            // Drop the columns
            $table->dropColumn(['level', 'first_program']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan', function (Blueprint $table) {
            // Re-add the columns
            $table->unsignedBigInteger('level');
            $table->unsignedBigInteger('first_program');
            
            // Re-add foreign key constraints
            $table->foreign('level')->references('id')->on('m_level');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });
    }
};
