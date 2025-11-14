<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration cleans up leftovers in the dev database to match the fresh database structure.
     * It removes columns/tables that shouldn't exist and adds missing columns.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        // Disable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        try {
            // 1. Drop q_plan_match table (if it exists - leftover from old schema)
            if (Schema::hasTable('q_plan_match')) {
                try {
                    Schema::dropIfExists('q_plan_match');
                    echo "  ✓ Dropped q_plan_match table\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to drop q_plan_match: " . $e->getMessage() . "\n";
                }
            }
            
            // 2. Remove event.enddate column (if it exists - leftover)
            if (Schema::hasTable('event') && Schema::hasColumn('event', 'enddate')) {
                try {
                    Schema::table('event', function (Blueprint $table) {
                        $table->dropColumn('enddate');
                    });
                    echo "  ✓ Removed event.enddate column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove event.enddate: " . $e->getMessage() . "\n";
                }
            }
            
            // 3. Remove plan.public column (if it exists - leftover)
            if (Schema::hasTable('plan') && Schema::hasColumn('plan', 'public')) {
                try {
                    Schema::table('plan', function (Blueprint $table) {
                        $table->dropColumn('public');
                    });
                    echo "  ✓ Removed plan.public column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove plan.public: " . $e->getMessage() . "\n";
                }
            }
            
            // 4. Remove user.is_admin column (if it exists - leftover)
            if (Schema::hasTable('user') && Schema::hasColumn('user', 'is_admin')) {
                try {
                    Schema::table('user', function (Blueprint $table) {
                        $table->dropColumn('is_admin');
                    });
                    echo "  ✓ Removed user.is_admin column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove user.is_admin: " . $e->getMessage() . "\n";
                }
            }
            
            // 5. Drop plan_extra_block table completely (no longer needed)
            if (Schema::hasTable('plan_extra_block')) {
                try {
                    // Drop foreign keys first if they exist
                    $fkInfo = DB::select("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'plan_extra_block'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ", [DB::connection()->getDatabaseName()]);
                    
                    foreach ($fkInfo as $fk) {
                        try {
                            DB::statement("ALTER TABLE `plan_extra_block` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                        } catch (\Throwable $e) {
                            // Ignore if FK doesn't exist
                        }
                    }
                    
                    Schema::dropIfExists('plan_extra_block');
                    echo "  ✓ Dropped plan_extra_block table\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to drop plan_extra_block table: " . $e->getMessage() . "\n";
                }
            }
            
            // 6. Remove logo.event column (if it exists - leftover in fresh, dev is correct)
            if (Schema::hasTable('logo') && Schema::hasColumn('logo', 'event')) {
                try {
                    // Drop foreign key first if it exists
                    $fkInfo = DB::select("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'logo'
                        AND COLUMN_NAME = 'event'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                        LIMIT 1
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($fkInfo)) {
                        $constraintName = $fkInfo[0]->CONSTRAINT_NAME;
                        try {
                            DB::statement("ALTER TABLE `logo` DROP FOREIGN KEY `{$constraintName}`");
                        } catch (\Throwable $e) {
                            // Ignore if FK doesn't exist
                        }
                    }
                    
                    Schema::table('logo', function (Blueprint $table) {
                        $table->dropColumn('event');
                    });
                    echo "  ✓ Removed logo.event column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove logo.event: " . $e->getMessage() . "\n";
                }
            }
            
            // 7. Remove plan_param_value.value column (if it exists - leftover in fresh, dev is correct)
            if (Schema::hasTable('plan_param_value') && Schema::hasColumn('plan_param_value', 'value')) {
                try {
                    Schema::table('plan_param_value', function (Blueprint $table) {
                        $table->dropColumn('value');
                    });
                    echo "  ✓ Removed plan_param_value.value column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove plan_param_value.value: " . $e->getMessage() . "\n";
                }
            }
            
            // 8. Remove table_event.name column (if it exists - leftover in fresh, dev is correct)
            if (Schema::hasTable('table_event') && Schema::hasColumn('table_event', 'name')) {
                try {
                    Schema::table('table_event', function (Blueprint $table) {
                        $table->dropColumn('name');
                    });
                    echo "  ✓ Removed table_event.name column\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to remove table_event.name: " . $e->getMessage() . "\n";
                }
            }
            
        } finally {
            // Re-enable foreign key checks
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Note: We don't reverse this migration as it's cleaning up leftovers.
     * Reverting would reintroduce incorrect schema elements.
     */
    public function down(): void
    {
        // Intentionally left empty - this is a cleanup migration
        // Reverting would reintroduce incorrect schema elements
    }
};
