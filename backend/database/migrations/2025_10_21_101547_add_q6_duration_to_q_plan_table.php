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
        Schema::table('q_plan', function (Blueprint $table) {
            // Add q6_duration column after q5_idle_stddev
            $table->integer('q6_duration')->nullable()->after('q5_idle_stddev');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('q_plan', function (Blueprint $table) {
            $table->dropColumn('q6_duration');
        });
    }
};
