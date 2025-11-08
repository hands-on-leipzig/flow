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
        Schema::table('m_supported_plan', function (Blueprint $table) {
            // Add jury_rounds column with default value of 0
            $table->unsignedSmallInteger('jury_rounds')->default(0)->after('tables');
        });
        
        // Set default value for existing records
        DB::table('m_supported_plan')
            ->whereNull('jury_rounds')
            ->update(['jury_rounds' => 0]);
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
