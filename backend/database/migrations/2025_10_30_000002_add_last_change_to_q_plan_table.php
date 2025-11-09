<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('q_plan', function (Blueprint $table) {
            // Match naming used in plan table: last_change
            if (!Schema::hasColumn('q_plan', 'last_change')) {
                $table->timestamp('last_change')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('q_plan', 'last_change')) {
            try {
                Schema::table('q_plan', function (Blueprint $table) {
                    $table->dropColumn('last_change');
                });
            } catch (\Throwable $e) {
                // Column might not exist or can't be dropped; ignore
            }
        }
    }
};


