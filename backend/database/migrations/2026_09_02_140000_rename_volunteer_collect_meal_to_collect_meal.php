<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event', 'volunteer_collect_meal') && ! Schema::hasColumn('event', 'collect_meal')) {
            Schema::table('event', function (Blueprint $table) {
                $table->renameColumn('volunteer_collect_meal', 'collect_meal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event', 'collect_meal') && ! Schema::hasColumn('event', 'volunteer_collect_meal')) {
            Schema::table('event', function (Blueprint $table) {
                $table->renameColumn('collect_meal', 'volunteer_collect_meal');
            });
        }
    }
};
