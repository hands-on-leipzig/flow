<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_help_screen')) {
            return;
        }

        $rows = [
            ['id' => 10, 'key' => 'live-check-in', 'name' => 'Check-In App', 'route_path' => '/plan/live/check-in', 'sort_order' => 8],
            ['id' => 11, 'key' => 'live-cockpit', 'name' => 'Cockpit App', 'route_path' => '/plan/live/cockpit', 'sort_order' => 9],
        ];

        foreach ($rows as $row) {
            if (DB::table('m_help_screen')->where('key', $row['key'])->exists()) {
                continue;
            }
            $payload = [
                'key' => $row['key'],
                'name' => $row['name'],
                'route_path' => $row['route_path'],
                'description' => null,
                'must_do' => null,
                'can_do' => null,
                'sort_order' => $row['sort_order'],
            ];
            if (! DB::table('m_help_screen')->where('id', $row['id'])->exists()) {
                $payload['id'] = $row['id'];
            }
            DB::table('m_help_screen')->insert($payload);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_help_screen')) {
            return;
        }

        DB::table('m_help_screen')->whereIn('key', ['live-check-in', 'live-cockpit'])->delete();
    }
};
