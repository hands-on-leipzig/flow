<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'calendar_stale')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('calendar_stale')->default(true)->after('needs_attention_checked_at');
                $table->index('calendar_stale');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'calendar_stale')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropIndex(['calendar_stale']);
                $table->dropColumn('calendar_stale');
            });
        }
    }
};
