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
        if (!Schema::hasTable('s_generator')) {
            return;
        }

        Schema::table('s_generator', function (Blueprint $table) {
            if (Schema::hasColumn('s_generator', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('s_generator', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('s_generator')) {
            return;
        }

        Schema::table('s_generator', function (Blueprint $table) {
            if (!Schema::hasColumn('s_generator', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('s_generator', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
