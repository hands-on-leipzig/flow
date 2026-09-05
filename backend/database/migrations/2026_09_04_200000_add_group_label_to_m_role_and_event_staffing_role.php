<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_role') && ! Schema::hasColumn('m_role', 'group_label')) {
            Schema::table('m_role', function (Blueprint $table) {
                $table->string('group_label', 150)->nullable()->after('staffable');
            });
        }

        if (Schema::hasTable('event_staffing_role') && ! Schema::hasColumn('event_staffing_role', 'group_label')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->string('group_label', 150)->nullable()->after('label');
            });
        }

        // Locked catalog strings. Must land before dummy-group deletion (next migration),
        // because deploy imports main-tables JSON only after all migrations.
        if (Schema::hasTable('m_role') && Schema::hasColumn('m_role', 'group_label')) {
            $labels = [
                4 => 'Jury-Gruppe',
                22 => 'Jury-Gruppe',
                9 => 'Gutachter:innengruppe',
                5 => 'Tisch',
                23 => 'Feld',
                11 => 'Robot-Check',
            ];
            foreach ($labels as $id => $label) {
                DB::table('m_role')->where('id', $id)->update(['group_label' => $label]);
            }
        }

        if (Schema::hasTable('event_staffing_role') && Schema::hasColumn('event_staffing_role', 'group_label')
            && Schema::hasTable('m_role') && Schema::hasColumn('m_role', 'group_label')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement(
                    'UPDATE event_staffing_role r
                     INNER JOIN m_role m ON m.id = r.m_role
                     SET r.group_label = m.group_label
                     WHERE r.m_role IS NOT NULL'
                );
            } else {
                foreach (DB::table('event_staffing_role')->whereNotNull('m_role')->get(['id', 'm_role']) as $row) {
                    $label = DB::table('m_role')->where('id', $row->m_role)->value('group_label');
                    DB::table('event_staffing_role')->where('id', $row->id)->update([
                        'group_label' => $label,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_role') && Schema::hasColumn('m_role', 'group_label')) {
            Schema::table('m_role', function (Blueprint $table) {
                $table->dropColumn('group_label');
            });
        }

        if (Schema::hasTable('event_staffing_role') && Schema::hasColumn('event_staffing_role', 'group_label')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->dropColumn('group_label');
            });
        }
    }
};
