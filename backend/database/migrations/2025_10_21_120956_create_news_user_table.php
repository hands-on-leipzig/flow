<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
                    $table->id();
                    $table->unsignedBigInteger('user_id');
                    $table->unsignedBigInteger('news_id');
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
                        $table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key can't be added (type mismatch)
                }
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or structure differs
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
