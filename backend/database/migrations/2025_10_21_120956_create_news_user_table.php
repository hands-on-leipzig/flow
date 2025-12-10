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
        // Only create if table doesn't exist - preserve existing data
        if (!Schema::hasTable('news_user')) {
            try {
                // Create table first without foreign keys
                Schema::create('news_user', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->unsignedInteger('user_id');
                    $table->unsignedInteger('news_id');
                    $table->timestamp('read_at')->useCurrent();
                    
                    $table->unique(['user_id', 'news_id']);
                });
                
                // Try to add foreign keys separately (may fail if column types don't match)
                try {
                    Schema::table('news_user', function (Blueprint $table) {
                        $table->foreign('user_id')->references('id')->on('user')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key can't be added (type mismatch)
                }
                
                try {
                    Schema::table('news_user', function (Blueprint $table) {
                        $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key can't be added (type mismatch)
                }
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or structure differs
            }
        } else {
            // Table exists - update news_id column type to match news.id (int instead of bigint)
            if (Schema::hasColumn('news_user', 'news_id')) {
                try {
                    // Drop foreign key first if it exists
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'news_user' 
                        AND REFERENCED_TABLE_NAME = 'news'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                    
                    // Change column type from bigint to int
                    DB::statement('ALTER TABLE `news_user` MODIFY COLUMN `news_id` INT(10) UNSIGNED NOT NULL');
                    
                    // Re-add foreign key
                    Schema::table('news_user', function (Blueprint $table) {
                        $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if column can't be modified or foreign key can't be added
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_user');
    }
};
