<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_help_action_screen')) {
            return;
        }
        if (Schema::hasColumn('m_help_action_screen', 'id')) {
            return;
        }

        Schema::table('m_help_action_screen', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->first();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_help_action_screen')) {
            return;
        }
        if (! Schema::hasColumn('m_help_action_screen', 'id')) {
            return;
        }

        Schema::table('m_help_action_screen', function (Blueprint $table) {
            $table->dropColumn('id');
        });
    }
};
