<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('m_activity_type_detail', 'public_time')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->boolean('public_time')->default(false)->after('presence');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'public_time')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->dropColumn('public_time');
            });
        }
    }
};
