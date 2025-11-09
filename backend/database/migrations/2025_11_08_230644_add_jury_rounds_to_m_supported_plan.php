<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only add column if it doesn't exist
        if (!Schema::hasColumn('m_supported_plan', 'jury_rounds')) {
            try {
                Schema::table('m_supported_plan', function (Blueprint $table) {
                    // Add jury_rounds column with default value of 0
                    $table->unsignedSmallInteger('jury_rounds')->default(0)->after('tables');
                });
            } catch (\Throwable $e) {
                // Column might already exist or can't be added; ignore
            }
        }
        
        // Set default value for existing records (if column exists)
        if (Schema::hasColumn('m_supported_plan', 'jury_rounds')) {
            try {
                DB::table('m_supported_plan')
                    ->whereNull('jury_rounds')
                    ->update(['jury_rounds' => 0]);
            } catch (\Throwable $e) {
                // Ignore if update fails
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_supported_plan', function (Blueprint $table) {
            $table->dropColumn('jury_rounds');
        });
    }
};
