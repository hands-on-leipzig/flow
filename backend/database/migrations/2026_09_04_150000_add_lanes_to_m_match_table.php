<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan key becomes (first_program, teams, lanes, tables).
 * Existing rows get lanes = 1.
 *
 * MySQL may use the old unique index as the supporting index for
 * m_match_first_program_foreign — drop/recreate FK around the unique swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_match')) {
            return;
        }

        if (! Schema::hasColumn('m_match', 'lanes')) {
            Schema::table('m_match', function (Blueprint $table) {
                $table->unsignedInteger('lanes')->default(1)->after('teams');
            });
        }

        DB::table('m_match')->where('lanes', 0)->update(['lanes' => 1]);

        $needsUniqueSwap = $this->uniqueExists('m_match_program_teams_tables_round_match_unique')
            || ! $this->uniqueExists('m_match_program_teams_lanes_tables_round_match_unique');

        if (! $needsUniqueSwap) {
            return;
        }

        Schema::table('m_match', function (Blueprint $table) {
            $table->dropForeign('m_match_first_program_foreign');
        });

        $this->dropUniqueIfExists('m_match_program_teams_tables_round_match_unique');

        if (! $this->uniqueExists('m_match_program_teams_lanes_tables_round_match_unique')) {
            Schema::table('m_match', function (Blueprint $table) {
                $table->unique(
                    ['first_program', 'teams', 'lanes', 'tables', 'round', 'match_no'],
                    'm_match_program_teams_lanes_tables_round_match_unique'
                );
            });
        }

        Schema::table('m_match', function (Blueprint $table) {
            $table->foreign('first_program', 'm_match_first_program_foreign')
                ->references('id')
                ->on('m_first_program')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_match')) {
            return;
        }

        Schema::table('m_match', function (Blueprint $table) {
            $table->dropForeign('m_match_first_program_foreign');
        });

        $this->dropUniqueIfExists('m_match_program_teams_lanes_tables_round_match_unique');

        if (Schema::hasColumn('m_match', 'lanes')) {
            Schema::table('m_match', function (Blueprint $table) {
                $table->dropColumn('lanes');
            });
        }

        if (! $this->uniqueExists('m_match_program_teams_tables_round_match_unique')) {
            Schema::table('m_match', function (Blueprint $table) {
                $table->unique(
                    ['first_program', 'teams', 'tables', 'round', 'match_no'],
                    'm_match_program_teams_tables_round_match_unique'
                );
            });
        }

        Schema::table('m_match', function (Blueprint $table) {
            $table->foreign('first_program', 'm_match_first_program_foreign')
                ->references('id')
                ->on('m_first_program')
                ->onDelete('restrict');
        });
    }

    private function uniqueExists(string $indexName): bool
    {
        $dbName = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', 'm_match')
            ->where('index_name', $indexName)
            ->exists();
    }

    private function dropUniqueIfExists(string $indexName): void
    {
        if (! $this->uniqueExists($indexName)) {
            return;
        }

        Schema::table('m_match', function (Blueprint $table) use ($indexName) {
            $table->dropUnique($indexName);
        });
    }
};
