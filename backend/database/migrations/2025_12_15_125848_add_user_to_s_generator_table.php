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
            $table->unsignedInteger('user')->nullable()->after('plan');
            $table->foreign('user')->references('id')->on('user')->onDelete('set null');
            $table->index('user');
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
            $table->dropForeign(['user']);
            $table->dropIndex(['user']);
            $table->dropColumn('user');
        });
    }
};
