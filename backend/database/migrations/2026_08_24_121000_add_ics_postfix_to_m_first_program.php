<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESERVED = ['de', 'at', 'ch'];

    public function up(): void
    {
        if (! Schema::hasColumn('m_first_program', 'ics_postfix')) {
            Schema::table('m_first_program', function (Blueprint $table) {
                $table->string('ics_postfix', 50)->nullable()->after('letter');
            });
        }

        $rows = DB::table('m_first_program')->select('id', 'name', 'ics_postfix')->get();

        foreach ($rows as $row) {
            if (is_string($row->ics_postfix) && $row->ics_postfix !== '') {
                continue;
            }

            $postfix = strtolower((string) $row->name);

            if (in_array($postfix, self::RESERVED, true)) {
                throw new RuntimeException(
                    "m_first_program id {$row->id} name '{$row->name}' maps to reserved ICS postfix '{$postfix}'."
                );
            }

            DB::table('m_first_program')->where('id', $row->id)->update([
                'ics_postfix' => $postfix,
            ]);
        }

        Schema::table('m_first_program', function (Blueprint $table) {
            $indexes = collect(DB::select('SHOW INDEX FROM `m_first_program`'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            if (! in_array('m_first_program_ics_postfix_unique', $indexes, true)) {
                $table->unique('ics_postfix', 'm_first_program_ics_postfix_unique');
            }
        });

        DB::statement('ALTER TABLE `m_first_program` MODIFY `ics_postfix` varchar(50) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('m_first_program', 'ics_postfix')) {
            return;
        }

        Schema::table('m_first_program', function (Blueprint $table) {
            $table->dropUnique('m_first_program_ics_postfix_unique');
            $table->dropColumn('ics_postfix');
        });
    }
};
