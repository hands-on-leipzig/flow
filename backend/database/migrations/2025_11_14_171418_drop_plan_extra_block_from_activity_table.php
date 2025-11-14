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
        if (Schema::hasTable('activity') && Schema::hasColumn('activity', 'plan_extra_block')) {
            try {
                // Check if there's a foreign key constraint on this column
                $dbName = DB::connection()->getDatabaseName();
                $fks = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = 'activity'
                    AND COLUMN_NAME = 'plan_extra_block'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$dbName]);

                // Drop foreign key if it exists
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `activity` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                        echo "  ✓ Dropped foreign key {$fk->CONSTRAINT_NAME}\n";
                    } catch (\Exception $e) {
                        // Foreign key might not exist, continue
                    }
                }

                // Drop the column
                Schema::table('activity', function (Blueprint $table) {
                    $table->dropColumn('plan_extra_block');
                });
                echo "  ✓ Dropped column activity.plan_extra_block\n";
            } catch (\Exception $e) {
                echo "  ⚠️  Failed to drop activity.plan_extra_block: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('activity') && !Schema::hasColumn('activity', 'plan_extra_block')) {
            try {
                Schema::table('activity', function (Blueprint $table) {
                    $table->unsignedInteger('plan_extra_block')->nullable()->after('extra_block');
                });
                echo "  ✓ Re-added column activity.plan_extra_block\n";
            } catch (\Exception $e) {
                echo "  ⚠️  Failed to re-add activity.plan_extra_block: " . $e->getMessage() . "\n";
            }
        }
    }
};
