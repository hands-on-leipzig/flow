<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('extra_block', 'public_time')) {
            Schema::table('extra_block', function (Blueprint $table) {
                $table->boolean('public_time')->default(false)->after('type');
            });
        }

        if (! Schema::hasColumn('activity', 'public_time')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->boolean('public_time')->default(false)->after('explore_group');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity', 'public_time')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->dropColumn('public_time');
            });
        }

        if (Schema::hasColumn('extra_block', 'public_time')) {
            Schema::table('extra_block', function (Blueprint $table) {
                $table->dropColumn('public_time');
            });
        }
    }
};
