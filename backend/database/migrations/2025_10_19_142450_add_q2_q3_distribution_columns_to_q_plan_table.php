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
            // Q2 (Table Diversity) distribution columns
            $table->integer('q2_1_count')->nullable()->after('q2_ok_count')->comment('Teams with 1 table');
            $table->integer('q2_2_count')->nullable()->after('q2_1_count')->comment('Teams with 2 tables');
            $table->integer('q2_3_count')->nullable()->after('q2_2_count')->comment('Teams with 3 tables');
            $table->decimal('q2_score_avg', 5, 2)->nullable()->after('q2_3_count')->comment('Average Q2 score (%)');
            
            // Q3 (Opponent Diversity) distribution columns
            $table->integer('q3_1_count')->nullable()->after('q3_ok_count')->comment('Teams with 1 opponent');
            $table->integer('q3_2_count')->nullable()->after('q3_1_count')->comment('Teams with 2 opponents');
            $table->integer('q3_3_count')->nullable()->after('q3_2_count')->comment('Teams with 3 opponents');
            $table->decimal('q3_score_avg', 5, 2)->nullable()->after('q3_3_count')->comment('Average Q3 score (%)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('q_plan', function (Blueprint $table) {
            $table->dropColumn([
                'q2_1_count',
                'q2_2_count',
                'q2_3_count',
                'q2_score_avg',
                'q3_1_count',
                'q3_2_count',
                'q3_3_count',
                'q3_score_avg',
            ]);
        });
    }
};
