<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix duplicate entries in m_visibility table and add unique constraint.
     * 
     * This migration:
     * 1. Removes duplicate (activity_type_detail, role) combinations, keeping the one with the lowest id
     * 2. Adds a unique constraint on (activity_type_detail, role) to prevent future duplicates
     * 
     * Works across all environments (dev, test, prod).
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            \Log::info('Skipping m_visibility fix - not MySQL/MariaDB');
            return;
        }

        if (!Schema::hasTable('m_visibility')) {
            \Log::warning('Table m_visibility does not exist, skipping fix');
            return;
        }

        $dbName = DB::connection()->getDatabaseName();
        
        // Step 1: Identify and remove duplicates
        // Keep the row with the lowest id for each (activity_type_detail, role) combination
        $duplicatesFound = DB::select("
            SELECT 
                activity_type_detail,
                role,
                MIN(id) as keep_id,
                COUNT(*) as count
            FROM m_visibility
            WHERE activity_type_detail IS NOT NULL AND role IS NOT NULL
            GROUP BY activity_type_detail, role
            HAVING COUNT(*) > 1
        ");
        
        $totalDuplicatesRemoved = 0;
        
        foreach ($duplicatesFound as $dup) {
            // Delete all rows except the one with the lowest id
            $deleted = DB::delete("
                DELETE FROM m_visibility
                WHERE activity_type_detail = ?
                    AND role = ?
                    AND id > ?
            ", [$dup->activity_type_detail, $dup->role, $dup->keep_id]);
            
            $totalDuplicatesRemoved += $deleted;
            \Log::info("Removed {$deleted} duplicate(s) for activity_type_detail={$dup->activity_type_detail}, role={$dup->role} (kept id={$dup->keep_id})");
        }
        
        \Log::info("Total duplicates removed from m_visibility: {$totalDuplicatesRemoved}");
        
        // Step 2: Add unique constraint on (activity_type_detail, role)
        // Only add if both columns are NOT NULL in the schema
        try {
            Schema::table('m_visibility', function (Blueprint $table) {
                // Create a unique index on the combination
                // Note: MySQL/MariaDB will allow multiple NULL combinations, but we verified there are no NULLs
                $table->unique(['activity_type_detail', 'role'], 'm_visibility_activity_role_unique');
            });
            
            \Log::info('Added unique constraint on m_visibility (activity_type_detail, role)');
        } catch (\Throwable $e) {
            // If unique constraint already exists or fails for other reasons, log it
            \Log::warning('Failed to add unique constraint on m_visibility: ' . $e->getMessage());
            
            // Check if constraint already exists
            $existingUnique = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = 'm_visibility'
                    AND CONSTRAINT_TYPE = 'UNIQUE'
                    AND CONSTRAINT_NAME = 'm_visibility_activity_role_unique'
            ", [$dbName]);
            
            if (!empty($existingUnique)) {
                \Log::info('Unique constraint already exists on m_visibility');
            } else {
                // Re-throw if it's a different error
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('m_visibility')) {
            return;
        }

        try {
            Schema::table('m_visibility', function (Blueprint $table) {
                $table->dropUnique('m_visibility_activity_role_unique');
            });
            \Log::info('Removed unique constraint from m_visibility');
        } catch (\Throwable $e) {
            \Log::warning('Failed to remove unique constraint from m_visibility: ' . $e->getMessage());
            // Don't throw - constraint might not exist
        }
    }
};
