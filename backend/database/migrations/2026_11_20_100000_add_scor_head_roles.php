<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['can_scor_head_referee', 'can_scor_head_judge'];

    public function up(): void
    {
        foreach (['user_regional_partner', 'event_user_access', 'event_invitation', 'access_log'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $nullable = $table === 'access_log';
            Schema::table($table, function (Blueprint $blueprint) use ($table, $nullable) {
                foreach (self::COLUMNS as $name) {
                    if (Schema::hasColumn($table, $name)) {
                        continue;
                    }
                    $column = $blueprint->boolean($name)->default(false);
                    if ($nullable) {
                        $column->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['access_log', 'event_invitation', 'event_user_access', 'user_regional_partner'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (self::COLUMNS as $name) {
                    if (Schema::hasColumn($table, $name)) {
                        $blueprint->dropColumn($name);
                    }
                }
            });
        }
    }
};
