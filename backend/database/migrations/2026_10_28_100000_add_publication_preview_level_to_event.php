<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'publication_preview_level')) {
            Schema::table('event', function (Blueprint $table) {
                $table->unsignedTinyInteger('publication_preview_level')->nullable()->after('public_team_data_entry');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'publication_preview_level')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('publication_preview_level');
            });
        }
    }
};
