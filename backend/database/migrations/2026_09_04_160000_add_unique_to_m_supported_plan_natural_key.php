<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Natural key uniqueness for supported grids.
 * Explore keeps tables NULL (no robot tables). MySQL UNIQUE still allows
 * multiple NULL tables rows; Challenge/Future (non-null tables) are fully unique.
 */
return new class extends Migration
{
    private const INDEX = 'm_supported_plan_program_teams_lanes_tables_unique';

    public function up(): void
    {
        if (! Schema::hasTable('m_supported_plan')) {
            return;
        }

        if ($this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('m_supported_plan', function (Blueprint $table) {
            $table->unique(
                ['first_program', 'teams', 'lanes', 'tables'],
                self::INDEX
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_supported_plan')) {
            return;
        }

        if (! $this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('m_supported_plan', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    private function indexExists(string $indexName): bool
    {
        $dbName = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', 'm_supported_plan')
            ->where('index_name', $indexName)
            ->exists();
    }
};
