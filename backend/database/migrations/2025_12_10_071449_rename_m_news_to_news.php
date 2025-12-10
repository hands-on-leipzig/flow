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
        // Only rename if m_news exists (for existing databases)
        if (Schema::hasTable('m_news')) {
            // Drop foreign key from news_user first
            if (Schema::hasTable('news_user')) {
                try {
                    $databaseName = DB::connection()->getDatabaseName();
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'news_user' 
                        AND REFERENCED_TABLE_NAME = 'm_news'
                    ", [$databaseName]);
                    
                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore if foreign key doesn't exist
                }
            }
            
            // Rename table
            DB::statement('RENAME TABLE `m_news` TO `news`');
            
            // Remove updated_at column if it exists
            if (Schema::hasColumn('news', 'updated_at')) {
                Schema::table('news', function (Blueprint $table) {
                    $table->dropColumn('updated_at');
                });
            }
            
            // Recreate foreign key in news_user
            if (Schema::hasTable('news_user')) {
                try {
                    Schema::table('news_user', function (Blueprint $table) {
                        $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key already exists
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse: rename news back to m_news
        if (Schema::hasTable('news')) {
            // Drop foreign key first
            if (Schema::hasTable('news_user')) {
                try {
                    $databaseName = DB::connection()->getDatabaseName();
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'news_user' 
                        AND REFERENCED_TABLE_NAME = 'news'
                    ", [$databaseName]);
                    
                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
            
            // Add updated_at column back
            if (!Schema::hasColumn('news', 'updated_at')) {
                Schema::table('news', function (Blueprint $table) {
                    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->after('created_at');
                });
            }
            
            // Rename back to m_news
            DB::statement('RENAME TABLE `news` TO `m_news`');
            
            // Recreate foreign key (references m_news since we just renamed it back)
            if (Schema::hasTable('news_user')) {
                try {
                    Schema::table('news_user', function (Blueprint $table) {
                        $table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }
};
