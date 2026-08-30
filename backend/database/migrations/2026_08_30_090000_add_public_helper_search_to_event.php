<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'public_helper_search')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('public_helper_search')->default(false)->after('qrcode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'public_helper_search')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('public_helper_search');
            });
        }
    }
};
