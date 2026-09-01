<?php

use App\Enums\FirstProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('table_event')) {
            return;
        }

        // Drop empty overrides before scoping by program.
        DB::table('table_event')
            ->where(function ($q) {
                $q->whereNull('table_name')->orWhere('table_name', '');
            })
            ->delete();

        if (! Schema::hasColumn('table_event', 'first_program')) {
            Schema::table('table_event', function (Blueprint $table) {
                $table->unsignedInteger('first_program')->nullable()->after('event');
            });
        }

        // Legacy rows were Challenge-only UI overrides.
        DB::table('table_event')
            ->whereNull('first_program')
            ->update(['first_program' => FirstProgram::CHALLENGE->value]);

        Schema::table('table_event', function (Blueprint $table) {
            // Make NOT NULL after backfill.
            $table->unsignedInteger('first_program')->nullable(false)->change();
        });

        // Unique per event + program + table number (drop prior unique if any).
        $this->dropIndexIfExists('table_event', 'table_event_event_first_program_table_number_unique');
        $this->dropIndexIfExists('table_event', 'table_event_event_table_number_unique');

        Schema::table('table_event', function (Blueprint $table) {
            $table->unique(
                ['event', 'first_program', 'table_number'],
                'table_event_event_first_program_table_number_unique'
            );
        });

        // FK to m_first_program (idempotent).
        $dbName = DB::getDatabaseName();
        $fkExists = DB::selectOne(
            "SELECT CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = 'table_event'
               AND COLUMN_NAME = 'first_program'
               AND REFERENCED_TABLE_NAME = 'm_first_program'
             LIMIT 1",
            [$dbName]
        );

        if (! $fkExists) {
            Schema::table('table_event', function (Blueprint $table) {
                $table->foreign('first_program')
                    ->references('id')
                    ->on('m_first_program')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('table_event') || ! Schema::hasColumn('table_event', 'first_program')) {
            return;
        }

        Schema::table('table_event', function (Blueprint $table) {
            $table->dropForeign(['first_program']);
        });

        $this->dropIndexIfExists('table_event', 'table_event_event_first_program_table_number_unique');

        Schema::table('table_event', function (Blueprint $table) {
            $table->dropColumn('first_program');
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        $dbName = DB::getDatabaseName();
        $exists = DB::selectOne(
            'SELECT 1 AS ok
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$dbName, $tableName, $indexName]
        );

        if ($exists) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }
    }
};
