<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_help_action_step')) {
            Schema::drop('m_help_action_step');
        }

        if (! Schema::hasTable('m_help_action_screen')) {
            Schema::create('m_help_action_screen', function (Blueprint $table) {
                $table->unsignedInteger('help_action');
                $table->unsignedInteger('help_screen');
                $table->unique(['help_action', 'help_screen']);
                $table->foreign('help_action')
                    ->references('id')
                    ->on('m_help_action')
                    ->cascadeOnDelete();
                $table->foreign('help_screen')
                    ->references('id')
                    ->on('m_help_screen')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('m_help_action') && Schema::hasColumn('m_help_action', 'help_screen')) {
            $rows = DB::table('m_help_action')
                ->whereNotNull('help_screen')
                ->get(['id', 'help_screen']);
            foreach ($rows as $row) {
                DB::table('m_help_action_screen')->insertOrIgnore([
                    'help_action' => $row->id,
                    'help_screen' => $row->help_screen,
                ]);
            }

            Schema::table('m_help_action', function (Blueprint $table) {
                $table->dropForeign(['help_screen']);
                $table->dropColumn('help_screen');
            });
        }

        if (Schema::hasTable('m_help_action') && ! Schema::hasColumn('m_help_action', 'body')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->text('body')->nullable();
            });
        }

        if (Schema::hasTable('m_help_action') && ! Schema::hasColumn('m_help_action', 'open_count')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->unsignedInteger('open_count')->default(0);
                $table->unsignedInteger('helpful_yes')->default(0);
                $table->unsignedInteger('helpful_no')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_help_action') && Schema::hasColumn('m_help_action', 'open_count')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->dropColumn(['open_count', 'helpful_yes', 'helpful_no']);
            });
        }

        if (Schema::hasTable('m_help_action') && Schema::hasColumn('m_help_action', 'body')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->dropColumn('body');
            });
        }

        if (Schema::hasTable('m_help_action') && ! Schema::hasColumn('m_help_action', 'help_screen')) {
            Schema::table('m_help_action', function (Blueprint $table) {
                $table->unsignedInteger('help_screen')->nullable();
            });

            $pairs = DB::table('m_help_action_screen')->get();
            foreach ($pairs as $pair) {
                DB::table('m_help_action')->where('id', $pair->help_action)->update([
                    'help_screen' => $pair->help_screen,
                ]);
            }

            Schema::table('m_help_action', function (Blueprint $table) {
                $table->unsignedInteger('help_screen')->nullable(false)->change();
                $table->foreign('help_screen')
                    ->references('id')
                    ->on('m_help_screen')
                    ->restrictOnDelete();
            });
        }

        Schema::dropIfExists('m_help_action_screen');

        if (! Schema::hasTable('m_help_action_step')) {
            Schema::create('m_help_action_step', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('help_action');
                $table->text('body');
                $table->unsignedInteger('sort_order');
                $table->foreign('help_action')
                    ->references('id')
                    ->on('m_help_action')
                    ->cascadeOnDelete();
            });
        }
    }
};
