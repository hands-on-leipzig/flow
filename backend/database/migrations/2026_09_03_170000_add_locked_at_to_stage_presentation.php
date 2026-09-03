<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_presentation') && ! Schema::hasColumn('stage_presentation', 'locked_at')) {
            Schema::table('stage_presentation', function (Blueprint $table) {
                // When the selection was last locked. Overwritten on each lock;
                // there is deliberately no history.
                $table->dateTime('locked_at')->nullable()->after('locked');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stage_presentation') && Schema::hasColumn('stage_presentation', 'locked_at')) {
            Schema::table('stage_presentation', function (Blueprint $table) {
                $table->dropColumn('locked_at');
            });
        }
    }
};
