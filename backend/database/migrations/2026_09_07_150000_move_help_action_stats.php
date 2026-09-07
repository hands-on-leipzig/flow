<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('help_action_stat')) {
            Schema::create('help_action_stat', function (Blueprint $table) {
                $table->unsignedInteger('help_action')->primary();
                $table->unsignedInteger('open_count')->default(0);
                $table->unsignedInteger('helpful_yes')->default(0);
                $table->unsignedInteger('helpful_no')->default(0);
                $table->foreign('help_action')
                    ->references('id')
                    ->on('m_help_action')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('m_help_action') && Schema::hasColumn('m_help_action', 'open_count')) {
            $rows = DB::table('m_help_action')->get(['id', 'open_count', 'helpful_yes', 'helpful_no']);
            foreach ($rows as $row) {
                DB::table('help_action_stat')->insertOrIgnore([
                    'help_action' => $row->id,
                    'open_count' => (int) $row->open_count,
                    'helpful_yes' => (int) $row->helpful_yes,
                    'helpful_no' => (int) $row->helpful_no,
                ]);
            }

            Schema::table('m_help_action', function (Blueprint $table) {
                $table->dropColumn(['open_count', 'helpful_yes', 'helpful_no']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_help_action') && ! Schema::hasColumn('m_help_action', 'open_count')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->unsignedInteger('open_count')->default(0);
                $table->unsignedInteger('helpful_yes')->default(0);
                $table->unsignedInteger('helpful_no')->default(0);
            });
        }

        if (Schema::hasTable('help_action_stat') && Schema::hasTable('m_help_action')) {
            $rows = DB::table('help_action_stat')->get();
            foreach ($rows as $row) {
                DB::table('m_help_action')->where('id', $row->help_action)->update([
                    'open_count' => (int) $row->open_count,
                    'helpful_yes' => (int) $row->helpful_yes,
                    'helpful_no' => (int) $row->helpful_no,
                ]);
            }
        }

        Schema::dropIfExists('help_action_stat');
    }
};
