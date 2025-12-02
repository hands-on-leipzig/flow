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
        if (!Schema::hasTable('publication')) {
            return; // Table doesn't exist, nothing to migrate
        }

        // Add last_change column (nullable initially)
        if (!Schema::hasColumn('publication', 'last_change')) {
            Schema::table('publication', function (Blueprint $table) {
                $table->timestamp('last_change')->nullable()->after('level');
            });
        }

        // Copy data from updated_at to last_change (fallback to created_at if updated_at is null)
        // Build COALESCE expression based on which columns exist
        $hasUpdatedAt = Schema::hasColumn('publication', 'updated_at');
        $hasCreatedAt = Schema::hasColumn('publication', 'created_at');
        
        if ($hasUpdatedAt && $hasCreatedAt) {
            DB::statement('
                UPDATE publication 
                SET last_change = COALESCE(updated_at, created_at, NOW())
                WHERE last_change IS NULL
            ');
        } elseif ($hasCreatedAt) {
            DB::statement('
                UPDATE publication 
                SET last_change = COALESCE(created_at, NOW())
                WHERE last_change IS NULL
            ');
        } else {
            // Neither column exists, just set to NOW()
            DB::statement('
                UPDATE publication 
                SET last_change = NOW()
                WHERE last_change IS NULL
            ');
        }

        // Make last_change not nullable
        Schema::table('publication', function (Blueprint $table) {
            $table->timestamp('last_change')->nullable(false)->change();
        });

        // Drop created_at and updated_at columns
        Schema::table('publication', function (Blueprint $table) {
            if (Schema::hasColumn('publication', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('publication', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('publication')) {
            return; // Table doesn't exist, nothing to rollback
        }

        // Add back created_at and updated_at
        Schema::table('publication', function (Blueprint $table) {
            if (!Schema::hasColumn('publication', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('level');
            }
            if (!Schema::hasColumn('publication', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // Copy data from last_change to updated_at and created_at
        DB::statement('
            UPDATE publication 
            SET updated_at = last_change,
                created_at = last_change
            WHERE updated_at IS NULL OR created_at IS NULL
        ');

        // Drop last_change column
        Schema::table('publication', function (Blueprint $table) {
            if (Schema::hasColumn('publication', 'last_change')) {
                $table->dropColumn('last_change');
            }
        });
    }
};
