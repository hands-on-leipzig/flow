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
        $driver = DB::connection()->getDriverName();
        
        // Disable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        try {
            // Fix contao_public_rounds.event_id
            if (Schema::hasTable('contao_public_rounds') && Schema::hasColumn('contao_public_rounds', 'event_id')) {
                try {
                    // Check current type
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'contao_public_rounds'
                        AND COLUMN_NAME = 'event_id'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        // Drop foreign key if exists
                        $fkInfo = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = 'contao_public_rounds'
                            AND COLUMN_NAME = 'event_id'
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName()]);
                        
                        if (!empty($fkInfo)) {
                            $constraintName = $fkInfo[0]->CONSTRAINT_NAME;
                            try {
                                DB::statement("ALTER TABLE `contao_public_rounds` DROP FOREIGN KEY `{$constraintName}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        // Modify column (it's a primary key, so no null)
                        DB::statement("ALTER TABLE `contao_public_rounds` MODIFY COLUMN `event_id` INT(10) UNSIGNED NOT NULL");
                        
                        // Re-add foreign key
                        if (Schema::hasTable('event')) {
                            try {
                                DB::statement("ALTER TABLE `contao_public_rounds` ADD CONSTRAINT `contao_public_rounds_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE");
                            } catch (\Throwable $e) {
                                // Ignore if FK can't be added
                            }
                        }
                        
                        echo "  ✓ Fixed contao_public_rounds.event_id\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix contao_public_rounds.event_id: " . $e->getMessage() . "\n";
                }
            }
            
            // Fix q_plan.id
            if (Schema::hasTable('q_plan') && Schema::hasColumn('q_plan', 'id')) {
                try {
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE, EXTRA
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'q_plan'
                        AND COLUMN_NAME = 'id'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        $isAutoIncrement = strpos($columnInfo[0]->EXTRA, 'auto_increment') !== false;
                        
                        if ($isAutoIncrement) {
                            DB::statement("ALTER TABLE `q_plan` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                            DB::statement("ALTER TABLE `q_plan` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
                        } else {
                            DB::statement("ALTER TABLE `q_plan` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                        }
                        
                        echo "  ✓ Fixed q_plan.id\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix q_plan.id: " . $e->getMessage() . "\n";
                }
            }
            
            // Fix q_run.id
            if (Schema::hasTable('q_run') && Schema::hasColumn('q_run', 'id')) {
                try {
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE, EXTRA
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'q_run'
                        AND COLUMN_NAME = 'id'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        $isAutoIncrement = strpos($columnInfo[0]->EXTRA, 'auto_increment') !== false;
                        
                        if ($isAutoIncrement) {
                            DB::statement("ALTER TABLE `q_run` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                            DB::statement("ALTER TABLE `q_run` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
                        } else {
                            DB::statement("ALTER TABLE `q_run` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                        }
                        
                        echo "  ✓ Fixed q_run.id\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix q_run.id: " . $e->getMessage() . "\n";
                }
            }
            
            // Fix slide.slideshow_id
            if (Schema::hasTable('slide') && Schema::hasColumn('slide', 'slideshow_id')) {
                try {
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE, IS_NULLABLE
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'slide'
                        AND COLUMN_NAME = 'slideshow_id'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        // Drop foreign key if exists
                        $fkInfo = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = 'slide'
                            AND COLUMN_NAME = 'slideshow_id'
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName()]);
                        
                        if (!empty($fkInfo)) {
                            $constraintName = $fkInfo[0]->CONSTRAINT_NAME;
                            try {
                                DB::statement("ALTER TABLE `slide` DROP FOREIGN KEY `{$constraintName}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        // Modify column
                        $isNullable = $columnInfo[0]->IS_NULLABLE === 'YES';
                        $nullClause = $isNullable ? 'NULL' : 'NOT NULL';
                        DB::statement("ALTER TABLE `slide` MODIFY COLUMN `slideshow_id` INT(10) UNSIGNED {$nullClause}");
                        
                        // Re-add foreign key
                        if (Schema::hasTable('slideshow')) {
                            try {
                                DB::statement("ALTER TABLE `slide` ADD CONSTRAINT `slide_slideshow_id_foreign` FOREIGN KEY (`slideshow_id`) REFERENCES `slideshow` (`id`) ON DELETE CASCADE");
                            } catch (\Throwable $e) {
                                // Ignore if FK can't be added
                            }
                        }
                        
                        echo "  ✓ Fixed slide.slideshow_id\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix slide.slideshow_id: " . $e->getMessage() . "\n";
                }
            }
            
            // Fix slideshow.id
            if (Schema::hasTable('slideshow') && Schema::hasColumn('slideshow', 'id')) {
                try {
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE, EXTRA
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'slideshow'
                        AND COLUMN_NAME = 'id'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        $isAutoIncrement = strpos($columnInfo[0]->EXTRA, 'auto_increment') !== false;
                        
                        // Get all foreign keys that reference slideshow.id
                        $referencingFks = DB::select("
                            SELECT TABLE_NAME, CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND REFERENCED_TABLE_NAME = 'slideshow'
                            AND REFERENCED_COLUMN_NAME = 'id'
                        ", [DB::connection()->getDatabaseName()]);
                        
                        // Drop all foreign keys that reference slideshow.id
                        foreach ($referencingFks as $fk) {
                            try {
                                DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        if ($isAutoIncrement) {
                            DB::statement("ALTER TABLE `slideshow` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                            DB::statement("ALTER TABLE `slideshow` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
                        } else {
                            DB::statement("ALTER TABLE `slideshow` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                        }
                        
                        // Re-add foreign keys
                        foreach ($referencingFks as $fk) {
                            if (Schema::hasTable($fk->TABLE_NAME)) {
                                try {
                                    // Determine ON DELETE action (CASCADE for slide.slideshow_id)
                                    $onDelete = ($fk->TABLE_NAME === 'slide') ? 'CASCADE' : 'RESTRICT';
                                    DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}` FOREIGN KEY (`slideshow_id`) REFERENCES `slideshow` (`id`) ON DELETE {$onDelete}");
                                } catch (\Throwable $e) {
                                    // Ignore if FK can't be re-added
                                }
                            }
                        }
                        
                        echo "  ✓ Fixed slideshow.id\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix slideshow.id: " . $e->getMessage() . "\n";
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
     */
    public function down(): void
    {
        // Note: We don't reverse this migration as it's a one-way schema update
        // Reverting would require knowing the original column types, which is complex
    }
};
