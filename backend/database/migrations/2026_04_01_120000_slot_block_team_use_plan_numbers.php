<?php

use App\Enums\FirstProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function dropForeignKeysForColumn(string $tableName, string $columnName): void
    {
        $dbName = DB::getDatabaseName();
        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$dbName, $tableName, $columnName]
        );

        foreach ($constraints as $constraint) {
            $name = $constraint->CONSTRAINT_NAME ?? null;
            if (! $name) {
                continue;
            }
            DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$name}`");
        }
    }

    private function dropUniqueIndexIfExists(string $tableName, string $indexName): void
    {
        $exists = DB::select(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [DB::getDatabaseName(), $tableName, $indexName]
        );

        if (! empty($exists)) {
            DB::statement("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('slot_block_team')) {
            return;
        }

        Schema::table('slot_block_team', function (Blueprint $table) {
            if (! Schema::hasColumn('slot_block_team', 'team_number_plan')) {
                $table->unsignedInteger('team_number_plan')->nullable()->after('extra_block');
            }
            if (! Schema::hasColumn('slot_block_team', 'first_program')) {
                $table->unsignedTinyInteger('first_program')->nullable()->after('team_number_plan');
            }
        });

        $rows = DB::table('slot_block_team as sbt')
            ->join('extra_block as eb', 'eb.id', '=', 'sbt.extra_block')
            ->leftJoin('team_plan as tp', function ($j) {
                $j->on('tp.team', '=', 'sbt.team')
                    ->on('tp.plan', '=', 'eb.plan');
            })
            ->leftJoin('team as t', 't.id', '=', 'sbt.team')
            ->select([
                'sbt.id',
                'tp.team_number_plan',
                't.first_program as team_first_program',
            ])
            ->get();

        foreach ($rows as $row) {
            $fp = (int) ($row->team_first_program ?? 0);
            $normalized = $fp === FirstProgram::CHALLENGE->value
                ? FirstProgram::CHALLENGE->value
                : FirstProgram::EXPLORE->value;

            DB::table('slot_block_team')->where('id', $row->id)->update([
                'team_number_plan' => $row->team_number_plan,
                'first_program' => $normalized,
            ]);
        }

        DB::table('slot_block_team')
            ->whereNull('team_number_plan')
            ->delete();

        $this->dropForeignKeysForColumn('slot_block_team', 'team');
        $this->dropForeignKeysForColumn('slot_block_team', 'extra_block');
        $this->dropUniqueIndexIfExists('slot_block_team', 'slot_block_team_extra_block_team_unique');

        if (Schema::hasColumn('slot_block_team', 'team')) {
            Schema::table('slot_block_team', function (Blueprint $table) {
                $table->dropColumn('team');
            });
        }

        DB::statement('ALTER TABLE slot_block_team MODIFY `team_number_plan` INT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE slot_block_team MODIFY `first_program` TINYINT UNSIGNED NOT NULL');

        Schema::table('slot_block_team', function (Blueprint $table) {
            $table->unique(['extra_block', 'first_program', 'team_number_plan'], 'slot_block_team_block_program_teamplan_unique');
            $table->foreign('extra_block')->references('id')->on('extra_block')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('slot_block_team')) {
            return;
        }

        Schema::table('slot_block_team', function (Blueprint $table) {
            if (! Schema::hasColumn('slot_block_team', 'team')) {
                $table->unsignedInteger('team')->nullable()->after('extra_block');
            }
        });

        $rows = DB::table('slot_block_team as sbt')
            ->join('extra_block as eb', 'eb.id', '=', 'sbt.extra_block')
            ->leftJoin('team_plan as tp', function ($j) {
                $j->on('tp.plan', '=', 'eb.plan')
                    ->on('tp.team_number_plan', '=', 'sbt.team_number_plan');
            })
            ->select(['sbt.id', 'tp.team'])
            ->get();

        foreach ($rows as $row) {
            DB::table('slot_block_team')->where('id', $row->id)->update([
                'team' => $row->team,
            ]);
        }

        DB::table('slot_block_team')->whereNull('team')->delete();

        $this->dropUniqueIndexIfExists('slot_block_team', 'slot_block_team_block_program_teamplan_unique');

        if (Schema::hasColumn('slot_block_team', 'first_program')) {
            Schema::table('slot_block_team', function (Blueprint $table) {
                $table->dropColumn('first_program');
            });
        }
        if (Schema::hasColumn('slot_block_team', 'team_number_plan')) {
            Schema::table('slot_block_team', function (Blueprint $table) {
                $table->dropColumn('team_number_plan');
            });
        }

        DB::statement('ALTER TABLE slot_block_team MODIFY `team` INT UNSIGNED NOT NULL');

        Schema::table('slot_block_team', function (Blueprint $table) {
            $table->unique(['extra_block', 'team']);
            $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
        });
    }
};
