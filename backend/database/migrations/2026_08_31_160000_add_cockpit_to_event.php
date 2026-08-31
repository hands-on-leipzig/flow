<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'cockpit_enabled')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('cockpit_enabled')->default(false)->after('check_in_text_helpers');
                $table->text('cockpit_pin')->nullable()->after('cockpit_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'cockpit_enabled')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn([
                    'cockpit_enabled',
                    'cockpit_pin',
                ]);
            });
        }
    }
};
