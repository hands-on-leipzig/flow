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
        if (Schema::hasColumn('m_activity_type', 'overview_plan_column')) {
            try {
                // First, set default values for any null values
                DB::table('m_activity_type')
                    ->whereNull('overview_plan_column')
                    ->update(['overview_plan_column' => '']);
                
                // Then make the column NOT NULL
                Schema::table('m_activity_type', function (Blueprint $table) {
                    $table->string('overview_plan_column', 100)->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be modified; ignore
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_activity_type', function (Blueprint $table) {
            $table->string('overview_plan_column', 100)->nullable()->change();
        });
    }
};
