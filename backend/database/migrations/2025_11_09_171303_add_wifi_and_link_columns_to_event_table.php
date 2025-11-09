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
        // Add columns if they don't exist (from create_master_tables.php)
        if (!Schema::hasColumn('event', 'link')) {
            Schema::table('event', function (Blueprint $table) {
                $table->string('link', 255)->nullable()->after('days');
            });
        }
        
        if (!Schema::hasColumn('event', 'wifi_ssid')) {
            Schema::table('event', function (Blueprint $table) {
                $table->string('wifi_ssid', 255)->nullable()->after('qrcode');
            });
        }
        
        if (!Schema::hasColumn('event', 'wifi_password')) {
            Schema::table('event', function (Blueprint $table) {
                $table->longText('wifi_password')->nullable()->after('wifi_ssid');
            });
        }
        
        if (!Schema::hasColumn('event', 'wifi_instruction')) {
            Schema::table('event', function (Blueprint $table) {
                $table->text('wifi_instruction')->nullable()->after('wifi_password');
            });
        }
        
        if (!Schema::hasColumn('event', 'wifi_qrcode')) {
            Schema::table('event', function (Blueprint $table) {
                $table->longText('wifi_qrcode')->nullable()->after('wifi_instruction');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove columns if they exist
        $columnsToDrop = [];
        if (Schema::hasColumn('event', 'wifi_qrcode')) {
            $columnsToDrop[] = 'wifi_qrcode';
        }
        if (Schema::hasColumn('event', 'wifi_instruction')) {
            $columnsToDrop[] = 'wifi_instruction';
        }
        if (Schema::hasColumn('event', 'wifi_password')) {
            $columnsToDrop[] = 'wifi_password';
        }
        if (Schema::hasColumn('event', 'wifi_ssid')) {
            $columnsToDrop[] = 'wifi_ssid';
        }
        if (Schema::hasColumn('event', 'link')) {
            $columnsToDrop[] = 'link';
        }
        
        if (!empty($columnsToDrop)) {
            Schema::table('event', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }
};
