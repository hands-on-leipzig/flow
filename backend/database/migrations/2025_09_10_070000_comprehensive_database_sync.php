<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. s_generator table should already exist from previous migration
        // No need to create it here as it's handled by 2025_09_10_061841_create_s_generator_table.php

        // 2. Add missing columns to existing tables
        
        // Add level column to m_room_type (missing in test/prod)
        if (!Schema::hasColumn('m_room_type', 'level')) {
            Schema::table('m_room_type', function (Blueprint $table) {
                $table->unsignedBigInteger('level')->nullable()->after('room_type_group');
                $table->foreign('level')->references('id')->on('m_level');
            });
        }

        // Add plan_extra_block column to activity (missing in test/prod)
        if (!Schema::hasColumn('activity', 'plan_extra_block')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_extra_block')->nullable()->after('extra_block');
                $table->foreign('plan_extra_block')->references('id')->on('plan_extra_block')->onDelete('no action')->onUpdate('no action');
            });
        }

        // 3. Remove obsolete columns
        
        // Remove enddate from event table (exists in test/prod, should be removed)
        // Note: This is also handled by 2025_09_10_061929_remove_enddate_from_events_table.php
        // but we keep it here as a safety check for test/prod environments
        if (Schema::hasColumn('event', 'enddate')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('enddate');
            });
        }

        // 4. Update foreign key constraints to match dev structure
        
        // Update activity table constraints
        if (Schema::hasTable('activity')) {
            // Drop old constraint if it exists
            $this->dropForeignKeyIfExists('activity', 'fk_plan_extra_Block');
            
            // Add new constraint
            Schema::table('activity', function (Blueprint $table) {
                $table->foreign('plan_extra_block')->references('id')->on('plan_extra_block')->onDelete('no action')->onUpdate('no action');
            });
        }

        // Update plan table constraints
        if (Schema::hasTable('plan')) {
            // Make event column NOT NULL if it's currently NULL
            if (Schema::hasColumn('plan', 'event')) {
                // First, update any NULL values to a default event (if needed)
                DB::table('plan')->whereNull('event')->update(['event' => 1]);
                
                // Then make the column NOT NULL
                Schema::table('plan', function (Blueprint $table) {
                    $table->unsignedBigInteger('event')->nullable(false)->change();
                });
            }
        }

        // 5. Update m_room_type constraints
        if (Schema::hasTable('m_room_type') && Schema::hasColumn('m_room_type', 'level')) {
            // Drop old constraint if it exists
            $this->dropForeignKeyIfExists('m_room_type', 'm_room_type_m_level_id_fk');
            
            // Add new constraint
            Schema::table('m_room_type', function (Blueprint $table) {
                $table->foreign('level')->references('id')->on('m_level');
            });
        }

        // 6. Update team table constraints
        if (Schema::hasTable('team')) {
            // Drop old constraint if it exists
            $this->dropForeignKeyIfExists('team', 'event');
            
            // Add new constraint
            Schema::table('team', function (Blueprint $table) {
                $table->foreign('event')->references('id')->on('event');
            });
        }

        // 7. Update team_plan constraints
        if (Schema::hasTable('team_plan')) {
            // Drop old constraints if they exist
            $this->dropForeignKeyIfExists('team_plan', 'team_plan_ibfk_3');
            $this->dropForeignKeyIfExists('team_plan', 'team_plan_ibfk_4');
            $this->dropForeignKeyIfExists('team_plan', 'team_plan_ibfk_5');
            
            // Add new constraint
            Schema::table('team_plan', function (Blueprint $table) {
                $table->foreign('room')->references('id')->on('room')->onDelete('set null')->onUpdate('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // s_generator table is handled by its own migration
        // No need to drop it here

        // Remove added columns
        if (Schema::hasColumn('m_room_type', 'level')) {
            Schema::table('m_room_type', function (Blueprint $table) {
                $table->dropForeign(['level']);
                $table->dropColumn('level');
            });
        }

        if (Schema::hasColumn('activity', 'plan_extra_block')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->dropForeign(['plan_extra_block']);
                $table->dropColumn('plan_extra_block');
            });
        }

        // Restore enddate column
        if (!Schema::hasColumn('event', 'enddate')) {
            Schema::table('event', function (Blueprint $table) {
                $table->timestamp('enddate')->nullable();
            });
        }
    }

    /**
     * Helper method to drop foreign key if it exists
     */
    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        try {
            // Get the actual foreign key constraint name from the database
            $constraints = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND CONSTRAINT_NAME = ?
            ", [$table, $constraint]);
            
            if (!empty($constraints)) {
                Schema::table($table, function (Blueprint $table) use ($constraint) {
                    $table->dropForeign([$constraint]);
                });
            }
        } catch (\Exception $e) {
            // Constraint doesn't exist or other error, ignore
        }
    }
};
