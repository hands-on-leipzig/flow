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
        Schema::create('news_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('news_id');
            $table->timestamp('read_at')->useCurrent();
            
            $table->foreign('user_id')->references('id')->on('user')->onDelete('cascade');
            $table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
            
            $table->unique(['user_id', 'news_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_user');
    }
};
