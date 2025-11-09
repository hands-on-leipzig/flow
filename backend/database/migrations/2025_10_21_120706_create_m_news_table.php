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
        // Only create if table doesn't exist (m_news is handled by refresh_m_tables.php)
        if (!Schema::hasTable('m_news')) {
            try {
                Schema::create('m_news', function (Blueprint $table) {
                    $table->unsignedInteger('id')->autoIncrement();
                    $table->string('title', 255);
                    $table->text('text');
                    $table->string('link', 500)->nullable();
                    $table->timestamp('created_at')->useCurrent();
                    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_news');
    }
};
