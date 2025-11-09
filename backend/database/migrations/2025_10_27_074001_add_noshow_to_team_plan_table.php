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
        Schema::table('team_plan', function (Blueprint $table) {
            $table->boolean('noshow')->default(false)->after('room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('team_plan', 'noshow')) {
            try {
                Schema::table('team_plan', function (Blueprint $table) {
                    $table->dropColumn('noshow');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be dropped; ignore
            }
        }
    }
};
