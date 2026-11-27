<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scor_event') || Schema::hasColumn('scor_event', 'public_rounds')) {
            return;
        }

        Schema::table('scor_event', function (Blueprint $table) {
            $table->json('public_rounds')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scor_event') || !Schema::hasColumn('scor_event', 'public_rounds')) {
            return;
        }

        Schema::table('scor_event', function (Blueprint $table) {
            $table->dropColumn('public_rounds');
        });
    }
};
