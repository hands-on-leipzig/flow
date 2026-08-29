<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presence semantics for public timeline rendering:
     * punctual | window | info
     */
    public function up(): void
    {
        if (! Schema::hasColumn('m_activity_type_detail', 'presence')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->string('presence', 20)->default('punctual')->after('code');
            });
        }

        // Soft / frame activities → background band in public schedule
        $windowCodes = [
            'c_lunch_break',
            'c_free_block', 'e_free_block', 'g_free_block',
            'c_slot_block', 'e_slot_block', 'g_slot_block',
            'g_opening', 'c_opening', 'e_opening', 'c_opening_day_1',
            'g_party_teams', 'g_party_volunteers',
            'e_exhibition',
        ];

        DB::table('m_activity_type_detail')
            ->whereIn('code', $windowCodes)
            ->update(['presence' => 'window']);

        // Optional / contextual
        $infoCodes = [
            'c_awards', 'e_awards', 'g_awards',
            'c_presentations',
        ];

        DB::table('m_activity_type_detail')
            ->whereIn('code', $infoCodes)
            ->update(['presence' => 'info']);

        // Everything else stays / becomes punctual (matches, jury, briefings, scoring, …)
        DB::table('m_activity_type_detail')
            ->whereNotIn('code', array_merge($windowCodes, $infoCodes))
            ->update(['presence' => 'punctual']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'presence')) {
            Schema::table('m_activity_type_detail', function (Blueprint $table) {
                $table->dropColumn('presence');
            });
        }
    }
};
