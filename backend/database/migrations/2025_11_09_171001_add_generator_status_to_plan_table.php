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
        // Add generator_status column if it doesn't exist
        if (!Schema::hasColumn('plan', 'generator_status')) {
            Schema::table('plan', function (Blueprint $table) {
                $table->string('generator_status', 50)->nullable()->after('last_change');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove generator_status column if it exists
        if (Schema::hasColumn('plan', 'generator_status')) {
            Schema::table('plan', function (Blueprint $table) {
                $table->dropColumn('generator_status');
            });
        }
    }
};
