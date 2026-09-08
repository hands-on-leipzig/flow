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
            ['id' => 7, 'key' => 'rooms', 'name' => 'Räume', 'route_path' => '/plan/rooms', 'sort_order' => 4],
            ['id' => 8, 'key' => 'volunteers-people', 'name' => 'Personen', 'route_path' => '/plan/volunteers', 'sort_order' => 5],
            ['id' => 9, 'key' => 'volunteers-staffing', 'name' => 'Zuordnung', 'route_path' => '/plan/volunteers/staffing', 'sort_order' => 7],
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

        DB::table('m_help_screen')->whereIn('key', ['rooms', 'volunteers-people', 'volunteers-staffing'])->delete();
    }
};
