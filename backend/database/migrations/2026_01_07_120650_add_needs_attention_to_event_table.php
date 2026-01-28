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
        Schema::table('event', function (Blueprint $table) {
            $table->boolean('needs_attention')->default(false)->after('wifi_qrcode');
            $table->timestamp('needs_attention_checked_at')->nullable()->after('needs_attention');
        });
        
        // Add index for efficient filtering in admin views
        Schema::table('event', function (Blueprint $table) {
            $table->index('needs_attention');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event', function (Blueprint $table) {
            $table->dropIndex(['needs_attention']);
            $table->dropColumn(['needs_attention', 'needs_attention_checked_at']);
        });
    }
};
