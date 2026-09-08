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

        $candidates = DB::table('m_help_screen')
            ->whereIn('key', ['teams-program', 'teams-explore'])
            ->orderBy('id')
            ->get();
        $keep = $candidates->firstWhere('key', 'teams-program') ?? $candidates->first();

        if (! $keep) {
            return;
        }

        $dropIds = DB::table('m_help_screen')
            ->whereIn('key', ['teams-explore', 'teams-challenge', 'teams-future_8'])
            ->where('id', '!=', $keep->id)
            ->pluck('id')
            ->all();

        if (Schema::hasTable('m_help_action_screen')) {
            foreach ($dropIds as $dropId) {
                $pairs = DB::table('m_help_action_screen')->where('help_screen', $dropId)->get();
                foreach ($pairs as $pair) {
                    $exists = DB::table('m_help_action_screen')
                        ->where('help_action', $pair->help_action)
                        ->where('help_screen', $keep->id)
                        ->exists();
                    if ($exists) {
                        DB::table('m_help_action_screen')
                            ->where('help_action', $pair->help_action)
                            ->where('help_screen', $dropId)
                            ->delete();
                    } else {
                        DB::table('m_help_action_screen')
                            ->where('help_action', $pair->help_action)
                            ->where('help_screen', $dropId)
                            ->update(['help_screen' => $keep->id]);
                    }
                }
            }
        }

        if ($dropIds !== []) {
            DB::table('m_help_screen')->whereIn('id', $dropIds)->delete();
        }

        DB::table('m_help_screen')->where('id', $keep->id)->update([
            'key' => 'teams-program',
            'name' => 'Details pro Team',
            'route_path' => '/plan/teams/:program',
        ]);
    }

    public function down(): void
    {
        // Irreversible merge of three Details-pro-Team screens into one.
    }
};
