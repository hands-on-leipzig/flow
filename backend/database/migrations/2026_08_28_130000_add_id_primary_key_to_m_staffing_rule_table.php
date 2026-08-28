<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_staffing_rule') || Schema::hasColumn('m_staffing_rule', 'id')) {
            return;
        }

        Schema::table('m_staffing_rule', function (Blueprint $table) {
            $table->dropForeign(['m_role']);
            $table->dropPrimary(['m_role']);
        });

        Schema::table('m_staffing_rule', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->first();
            $table->unique('m_role');
            $table->foreign('m_role')->references('id')->on('m_role')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_staffing_rule') || ! Schema::hasColumn('m_staffing_rule', 'id')) {
            return;
        }

        Schema::table('m_staffing_rule', function (Blueprint $table) {
            $table->dropForeign(['m_role']);
            $table->dropUnique(['m_role']);
            $table->dropColumn('id');
            $table->primary('m_role');
            $table->foreign('m_role')->references('id')->on('m_role')->onDelete('cascade');
        });
    }
};
