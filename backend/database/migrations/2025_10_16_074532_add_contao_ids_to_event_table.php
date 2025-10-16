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
        Schema::table('event', function (Blueprint $table) {
            $table->integer('contao_id_explore')->nullable()->after('event_challenge');
            $table->integer('contao_id_challenge')->nullable()->after('contao_id_explore');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event', function (Blueprint $table) {
            $table->dropColumn(['contao_id_explore', 'contao_id_challenge']);
        });
    }
};