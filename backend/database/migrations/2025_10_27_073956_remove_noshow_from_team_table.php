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
        // Only drop column if it exists
        if (Schema::hasColumn('team', 'noshow')) {
            try {
                Schema::table('team', function (Blueprint $table) {
                    $table->dropColumn('noshow');
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
        Schema::table('team', function (Blueprint $table) {
            $table->boolean('noshow')->default(false);
        });
    }
};
