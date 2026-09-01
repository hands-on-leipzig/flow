<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'public_volunteer_data_entry')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('public_volunteer_data_entry')->default(false)->after('public_helper_search');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'public_volunteer_data_entry')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('public_volunteer_data_entry');
            });
        }
    }
};
