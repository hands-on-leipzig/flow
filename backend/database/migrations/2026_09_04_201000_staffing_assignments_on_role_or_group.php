<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_staffing_assignment') || ! Schema::hasTable('event_staffing_role')) {
            return;
        }

        if (! Schema::hasColumn('event_staffing_role', 'surplus')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->boolean('surplus')->default(false)->after('sequence');
            });
        }

        if (! Schema::hasColumn('event_staffing_assignment', 'event_staffing_role')) {
            Schema::table('event_staffing_assignment', function (Blueprint $table) {
                $table->unsignedInteger('event_staffing_role')->nullable()->after('id');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        $mysql = $driver === 'mysql' || $driver === 'mariadb';

        if ($mysql) {
            DB::statement(
                'UPDATE event_staffing_assignment a
                 INNER JOIN event_staffing_group g ON g.id = a.event_staffing_group
                 SET a.event_staffing_role = g.event_staffing_role
                 WHERE a.event_staffing_role IS NULL'
            );
        } else {
            $roleIds = DB::table('event_staffing_group')->pluck('event_staffing_role', 'id');
            foreach (DB::table('event_staffing_assignment')->whereNull('event_staffing_role')->get() as $row) {
                $roleId = $roleIds[$row->event_staffing_group] ?? null;
                if ($roleId) {
                    DB::table('event_staffing_assignment')->where('id', $row->id)->update([
                        'event_staffing_role' => $roleId,
                    ]);
                }
            }
        }

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            try {
                $table->dropForeign(['event_staffing_group']);
            } catch (\Throwable) {
            }
        });

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            $table->unsignedInteger('event_staffing_group')->nullable()->change();
        });

        if ($mysql) {
            DB::statement(
                "UPDATE event_staffing_assignment a
                 INNER JOIN event_staffing_role r ON r.id = a.event_staffing_role
                 SET a.event_staffing_group = NULL
                 WHERE r.group_label IS NULL OR r.group_label = ''"
            );
            DB::statement(
                "DELETE g FROM event_staffing_group g
                 INNER JOIN event_staffing_role r ON r.id = g.event_staffing_role
                 WHERE r.group_label IS NULL OR r.group_label = ''"
            );
        } else {
            $ungroupedRoleIds = DB::table('event_staffing_role')
                ->where(function ($q) {
                    $q->whereNull('group_label')->orWhere('group_label', '');
                })
                ->pluck('id');
            if ($ungroupedRoleIds->isNotEmpty()) {
                DB::table('event_staffing_assignment')
                    ->whereIn('event_staffing_role', $ungroupedRoleIds)
                    ->update(['event_staffing_group' => null]);
                DB::table('event_staffing_group')
                    ->whereIn('event_staffing_role', $ungroupedRoleIds)
                    ->delete();
            }
        }

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            try {
                $table->dropUnique('event_staffing_assignment_unique');
            } catch (\Throwable) {
            }
        });

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            $table->unsignedInteger('event_staffing_role')->nullable(false)->change();
            $table->foreign('event_staffing_role')
                ->references('id')
                ->on('event_staffing_role')
                ->onDelete('cascade');
            $table->foreign('event_staffing_group')
                ->references('id')
                ->on('event_staffing_group')
                ->onDelete('cascade');
            $table->unique(
                ['event_staffing_role', 'volunteer_person'],
                'event_staffing_assignment_role_person_unique'
            );
            $table->unique(
                ['event_staffing_group', 'volunteer_person'],
                'event_staffing_assignment_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_staffing_assignment')) {
            return;
        }

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            try {
                $table->dropForeign(['event_staffing_role']);
            } catch (\Throwable) {
            }
            try {
                $table->dropForeign(['event_staffing_group']);
            } catch (\Throwable) {
            }
            try {
                $table->dropUnique('event_staffing_assignment_role_person_unique');
            } catch (\Throwable) {
            }
            try {
                $table->dropUnique('event_staffing_assignment_unique');
            } catch (\Throwable) {
            }
        });

        Schema::table('event_staffing_assignment', function (Blueprint $table) {
            if (Schema::hasColumn('event_staffing_assignment', 'event_staffing_role')) {
                $table->dropColumn('event_staffing_role');
            }
            $table->unsignedInteger('event_staffing_group')->nullable(false)->change();
            $table->foreign('event_staffing_group')
                ->references('id')
                ->on('event_staffing_group')
                ->onDelete('cascade');
            $table->unique(
                ['event_staffing_group', 'volunteer_person'],
                'event_staffing_assignment_unique'
            );
        });

        if (Schema::hasTable('event_staffing_role') && Schema::hasColumn('event_staffing_role', 'surplus')) {
            Schema::table('event_staffing_role', function (Blueprint $table) {
                $table->dropColumn('surplus');
            });
        }
    }
};
