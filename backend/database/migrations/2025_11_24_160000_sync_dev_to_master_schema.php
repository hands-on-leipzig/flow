<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * This migration syncs the dev database schema to match the master migration.
     * It fixes foreign keys, removes extra columns, and ensures consistency.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }
        
        try {
            // Fix m_parameter_condition foreign keys
            // Dev has SET NULL, master has CASCADE
            if (Schema::hasTable('m_parameter_condition')) {
                Schema::table('m_parameter_condition', function (Blueprint $table) {
                    // Drop existing foreign keys
                    $table->dropForeign(['parameter']);
                    $table->dropForeign(['if_parameter']);
                });
                
                Schema::table('m_parameter_condition', function (Blueprint $table) {
                    // Recreate with CASCADE delete
                    $table->foreign('parameter')->references('id')->on('m_parameter')->onDelete('cascade');
                    $table->foreign('if_parameter')->references('id')->on('m_parameter')->onDelete('cascade');
                });
            }

            // Fix m_visibility foreign keys
            // Dev has RESTRICT, master has CASCADE
            if (Schema::hasTable('m_visibility')) {
                Schema::table('m_visibility', function (Blueprint $table) {
                    // Drop existing foreign keys
                    $table->dropForeign(['activity_type_detail']);
                    $table->dropForeign(['role']);
                });
                
                Schema::table('m_visibility', function (Blueprint $table) {
                    // Recreate with CASCADE delete
                    $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail')->onDelete('cascade');
                    $table->foreign('role')->references('id')->on('m_role')->onDelete('cascade');
                });
            }

            // Fix event.regional_partner foreign key
            // Dev has NO ACTION, master has RESTRICT (they're equivalent but let's be explicit)
            if (Schema::hasTable('event')) {
                Schema::table('event', function (Blueprint $table) {
                    $table->dropForeign(['regional_partner']);
                });
                
                Schema::table('event', function (Blueprint $table) {
                    $table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('restrict');
                });
            }

            // Fix plan.event foreign key
            // Dev has NO ACTION, master should have CASCADE (but master migration shows cascade in comment)
            // Actually, looking at master, plan.event has onDelete('cascade') but dev shows NO ACTION
            if (Schema::hasTable('plan')) {
                Schema::table('plan', function (Blueprint $table) {
                    $table->dropForeign(['event']);
                });
                
                Schema::table('plan', function (Blueprint $table) {
                    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                });
            }

            // Add missing team.first_program foreign key
            // Dev doesn't have this FK, master has it
            if (Schema::hasTable('team') && Schema::hasColumn('team', 'first_program')) {
                // Check if FK already exists
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'team' 
                    AND COLUMN_NAME = 'first_program' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                if (empty($fks)) {
                    Schema::table('team', function (Blueprint $table) {
                        $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
                    });
                }
            }

            // Fix s_generator.plan foreign key
            // Dev has SET NULL, master has CASCADE
            if (Schema::hasTable('s_generator')) {
                Schema::table('s_generator', function (Blueprint $table) {
                    $table->dropForeign(['plan']);
                });
                
                Schema::table('s_generator', function (Blueprint $table) {
                    $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
                });
            }

            // Fix team_plan.room foreign keys
            // Dev has duplicate FKs (team_plan_ibfk_3 and team_plan_ibfk_4), master has single FK with SET NULL
            if (Schema::hasTable('team_plan')) {
                // Drop all existing room foreign keys
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'team_plan' 
                    AND COLUMN_NAME = 'room' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($fks as $fk) {
                    Schema::table('team_plan', function (Blueprint $table) use ($fk) {
                        $table->dropForeign([$fk->CONSTRAINT_NAME]);
                    });
                }
                
                // Recreate single FK with SET NULL
                Schema::table('team_plan', function (Blueprint $table) {
                    $table->foreign('room')->references('id')->on('room')->onDelete('set null');
                });
            }

            // Fix activity foreign keys
            // Dev has CASCADE for activity_group and room_type, SET NULL for extra_block
            // Master has CASCADE for activity_group and room_type, nullOnDelete() for extra_block (which is SET NULL)
            // These should already match, but let's ensure consistency
            if (Schema::hasTable('activity')) {
                // Check and fix activity_group FK
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME, DELETE_RULE
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                        AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                    WHERE kcu.TABLE_SCHEMA = ? 
                    AND kcu.TABLE_NAME = 'activity' 
                    AND kcu.COLUMN_NAME = 'activity_group' 
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($fks as $fk) {
                    if (strtoupper($fk->DELETE_RULE) !== 'CASCADE') {
                        Schema::table('activity', function (Blueprint $table) {
                            $table->dropForeign(['activity_group']);
                        });
                        Schema::table('activity', function (Blueprint $table) {
                            $table->foreign('activity_group')->references('id')->on('activity_group')->onDelete('cascade');
                        });
                        break;
                    }
                }
                
                // Check and fix room_type FK
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME, DELETE_RULE
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                        AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                    WHERE kcu.TABLE_SCHEMA = ? 
                    AND kcu.TABLE_NAME = 'activity' 
                    AND kcu.COLUMN_NAME = 'room_type' 
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($fks as $fk) {
                    if (strtoupper($fk->DELETE_RULE) !== 'CASCADE') {
                        Schema::table('activity', function (Blueprint $table) {
                            $table->dropForeign(['room_type']);
                        });
                        Schema::table('activity', function (Blueprint $table) {
                            $table->foreign('room_type')->references('id')->on('m_room_type')->onDelete('cascade');
                        });
                        break;
                    }
                }
                
                // Check and fix extra_block FK
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME, DELETE_RULE
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                        AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                    WHERE kcu.TABLE_SCHEMA = ? 
                    AND kcu.TABLE_NAME = 'activity' 
                    AND kcu.COLUMN_NAME = 'extra_block' 
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($fks as $fk) {
                    if (strtoupper($fk->DELETE_RULE) !== 'SET NULL') {
                        Schema::table('activity', function (Blueprint $table) {
                            $table->dropForeign(['extra_block']);
                        });
                        Schema::table('activity', function (Blueprint $table) {
                            $table->foreign('extra_block')->references('id')->on('extra_block')->onDelete('set null');
                        });
                        break;
                    }
                }
            }

            // Remove extra column from m_supported_plan
            // Dev has jury_rounds column, master doesn't
            if (Schema::hasTable('m_supported_plan') && Schema::hasColumn('m_supported_plan', 'jury_rounds')) {
                Schema::table('m_supported_plan', function (Blueprint $table) {
                    $table->dropColumn('jury_rounds');
                });
            }

            // Fix regional_partner.region column length
            // Dev has varchar(50), master has varchar(100)
            if (Schema::hasTable('regional_partner') && Schema::hasColumn('regional_partner', 'region')) {
                Schema::table('regional_partner', function (Blueprint $table) {
                    $table->string('region', 100)->change();
                });
            }

            // Fix regional_partner.dolibarr_id type
            // Dev has varchar(10), master has integer
            if (Schema::hasTable('regional_partner') && Schema::hasColumn('regional_partner', 'dolibarr_id')) {
                Schema::table('regional_partner', function (Blueprint $table) {
                    $table->integer('dolibarr_id')->nullable()->change();
                });
            }

            // Fix event_logo.id type
            // Dev has int(11), master has unsignedInteger
            if (Schema::hasTable('event_logo') && Schema::hasColumn('event_logo', 'id')) {
                // This is tricky - can't change auto_increment ID type easily
                // We'll skip this as it's not critical (both are integers)
            }

            // Fix room_type_room.id type
            // Dev has int(11), master has unsignedInteger
            // Similar issue - skip for now as both are integers

            // Fix user_regional_partner.id type
            // Dev has int(11), master has unsignedInteger
            // Similar issue - skip for now as both are integers

            // Fix publication.level type
            // Dev has int(11), master has unsignedInteger
            if (Schema::hasTable('publication') && Schema::hasColumn('publication', 'level')) {
                Schema::table('publication', function (Blueprint $table) {
                    $table->unsignedInteger('level')->change();
                });
            }

            // Fix plan_param_value - add unique constraint and missing FK
            // Dev doesn't have unique(plan, parameter), master has it
            // Dev is missing FK for parameter column
            if (Schema::hasTable('plan_param_value')) {
                // Check if unique constraint exists
                $uniqueExists = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'plan_param_value' 
                    AND CONSTRAINT_TYPE = 'UNIQUE'
                    AND CONSTRAINT_NAME != 'PRIMARY'
                ", [DB::connection()->getDatabaseName()]);
                
                // Check if there's a unique constraint on (plan, parameter)
                $planParamUnique = false;
                foreach ($uniqueExists as $constraint) {
                    $cols = DB::select("
                        SELECT COLUMN_NAME 
                        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'plan_param_value' 
                        AND CONSTRAINT_NAME = ?
                        ORDER BY ORDINAL_POSITION
                    ", [DB::connection()->getDatabaseName(), $constraint->CONSTRAINT_NAME]);
                    
                    $colNames = array_column($cols, 'COLUMN_NAME');
                    if (count($colNames) === 2 && in_array('plan', $colNames) && in_array('parameter', $colNames)) {
                        $planParamUnique = true;
                        break;
                    }
                }
                
                if (!$planParamUnique) {
                    // Add unique constraint
                    Schema::table('plan_param_value', function (Blueprint $table) {
                        $table->unique(['plan', 'parameter']);
                    });
                }
                
                // Check if parameter FK exists
                $paramFkExists = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'plan_param_value' 
                    AND COLUMN_NAME = 'parameter' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                if (empty($paramFkExists)) {
                    Schema::table('plan_param_value', function (Blueprint $table) {
                        $table->foreign('parameter')->references('id')->on('m_parameter')->onDelete('cascade');
                    });
                }
            }

            // Fix room.name default
            // Dev has 'Unnamed Room', master has no default (but column is NOT NULL)
            // Actually master has no default, so this is fine

            // Fix table_event column order
            // Dev has: event, table_number, table_name
            // Master has: id, table_name, table_number, event
            // Column order doesn't matter, but let's check if columns match
            if (Schema::hasTable('table_event')) {
                // Ensure all columns exist with correct types
                if (!Schema::hasColumn('table_event', 'table_name')) {
                    Schema::table('table_event', function (Blueprint $table) {
                        $table->string('table_name', 100)->after('id');
                    });
                }
                if (!Schema::hasColumn('table_event', 'table_number')) {
                    Schema::table('table_event', function (Blueprint $table) {
                        $table->integer('table_number')->after('table_name');
                    });
                }
            }

            // Fix s_one_link_access.id type
            // Dev has bigint(20) unsigned, master has unsignedInteger
            // This is a significant difference - unsignedInteger is int(10) unsigned
            if (Schema::hasTable('s_one_link_access') && Schema::hasColumn('s_one_link_access', 'id')) {
                // Changing from bigint to int could cause issues if IDs exceed int range
                // We'll leave this for now and document it
            }

            // Fix match table column types
            // Dev has int(11) for round, match_no, table_1, table_2, table_1_team, table_2_team
            // Master has unsignedInteger for all of these
            // Similar to above - these are integer types, difference is signed vs unsigned
            // We'll document but not change to avoid data issues

            // Remove q_plan_team.team foreign key
            // Dev has this FK, but master migration doesn't define it
            // Remove it to match master
            if (Schema::hasTable('q_plan_team')) {
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'q_plan_team' 
                    AND COLUMN_NAME = 'team' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($fks as $fk) {
                    Schema::table('q_plan_team', function (Blueprint $table) use ($fk) {
                        $table->dropForeign([$fk->CONSTRAINT_NAME]);
                    });
                }
            }

        } finally {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This rollback is complex and may not fully restore the previous state.
     * Consider backing up the database before running this migration.
     */
    public function down(): void
    {
        // Rollback is intentionally minimal as restoring exact previous state
        // would require storing all previous FK rules, which is complex.
        // If rollback is needed, restore from backup instead.
    }
};

