<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_activity_type', function (Blueprint $table) {
            $table->tinyInteger('sequence')->unsigned()->default(0)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('m_activity_type', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};