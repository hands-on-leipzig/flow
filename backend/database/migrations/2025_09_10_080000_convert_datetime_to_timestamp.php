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
        // Convert plan table columns from datetime to timestamp
        if (Schema::hasTable('plan')) {
            if (Schema::hasColumn('plan', 'created')) {
                try {
                    Schema::table('plan', function (Blueprint $table) {
                        $table->timestamp('created')->nullable()->change();
                    });
                } catch (\Throwable $e) {
                    // Column might not exist or can't be modified; ignore
                }
            }
            
            if (Schema::hasColumn('plan', 'last_change')) {
                try {
                    Schema::table('plan', function (Blueprint $table) {
                        $table->timestamp('last_change')->nullable()->change();
                    });
                } catch (\Throwable $e) {
                    // Column might not exist or can't be modified; ignore
                }
            }
        }

        // Convert q_run table columns from datetime to timestamp
        if (Schema::hasTable('q_run')) {
            if (Schema::hasColumn('q_run', 'started_at')) {
                try {
                    Schema::table('q_run', function (Blueprint $table) {
                        $table->timestamp('started_at')->nullable()->change();
                    });
                } catch (\Throwable $e) {
                    // Column might not exist or can't be modified; ignore
                }
            }
            
            if (Schema::hasColumn('q_run', 'finished_at')) {
                try {
                    Schema::table('q_run', function (Blueprint $table) {
                        $table->timestamp('finished_at')->nullable()->change();
                    });
                } catch (\Throwable $e) {
                    // Column might not exist or can't be modified; ignore
                }
            }
        }

        // Note: s_generator.start and s_generator.end are already timestamps
        // from the previous migration, so no changes needed there
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to datetime if needed
        if (Schema::hasTable('plan')) {
            Schema::table('plan', function (Blueprint $table) {
                if (Schema::hasColumn('plan', 'created')) {
                    $table->datetime('created')->nullable()->change();
                }
                
                if (Schema::hasColumn('plan', 'last_change')) {
                    $table->datetime('last_change')->nullable()->change();
                }
            });
        }

        if (Schema::hasTable('q_run')) {
            Schema::table('q_run', function (Blueprint $table) {
                if (Schema::hasColumn('q_run', 'started_at')) {
                    $table->datetime('started_at')->nullable()->change();
                }
                
                if (Schema::hasColumn('q_run', 'finished_at')) {
                    $table->datetime('finished_at')->nullable()->change();
                }
            });
        }
    }
};
