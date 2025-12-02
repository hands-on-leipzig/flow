<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Applications table - represents external applications that use the API
        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('contact_email', 255);
            $table->string('webhook_url', 500)->nullable();
            $table->json('allowed_ips')->nullable(); // IP whitelist
            $table->unsignedInteger('rate_limit')->default(1000); // Requests per hour
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('is_active');
            });
        }

        // API keys table - stores API keys for authentication
        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('name', 100); // Human-readable identifier
            $table->string('key_hash', 64)->unique(); // SHA256 hash of the API key
            $table->unsignedInteger('application_id');
            $table->json('scopes')->nullable(); // Array of permission scopes
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
            $table->index(['application_id', 'is_active']);
            $table->index('key_hash');
            });
        }

        // API request logs - for monitoring and analytics
        if (!Schema::hasTable('api_request_logs')) {
            Schema::create('api_request_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedInteger('application_id');
            $table->unsignedInteger('api_key_id')->nullable();
            $table->string('method', 10); // GET, POST, etc.
            $table->string('path', 500);
            $table->integer('status_code');
            $table->integer('response_time_ms');
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['application_id', 'created_at']);
            $table->index(['api_key_id', 'created_at']);
            $table->index('status_code');
            $table->index('created_at');
            
            $table->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
            $table->foreign('api_key_id')->references('id')->on('api_keys')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('applications');
    }
};

