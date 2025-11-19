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
        Schema::create('s_one_link_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event');
            $table->date('access_date');
            $table->timestamp('access_time')->nullable();
            
            // Server-side captured (from HTTP request)
            $table->text('user_agent')->nullable();
            $table->text('referrer')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('accept_language', 50)->nullable();
            
            // Client-side captured (sent from frontend)
            $table->unsignedSmallInteger('screen_width')->nullable();
            $table->unsignedSmallInteger('screen_height')->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->decimal('device_pixel_ratio', 3, 2)->nullable();
            $table->boolean('touch_support')->nullable();
            $table->string('connection_type', 20)->nullable();
            
            // Source tracking
            $table->string('source', 20)->nullable(); // 'qr', 'direct', 'referrer', 'unknown'
            
            // Indexes
            $table->index(['event', 'access_date'], 'idx_event_access_date');
            $table->index('access_date', 'idx_access_date');
            
            // Foreign key
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_one_link_access');
    }
};
