<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * activity.slot_team stores team_number_plan (like jury_team / table_*_team), not team.id.
     */
    public function up(): void
    {
        if (! Schema::hasTable('activity') || ! Schema::hasColumn('activity', 'slot_team')) {
            return;
        }

        try {
            Schema::table('activity', function (Blueprint $table) {
                $table->dropForeign(['slot_team']);
            });
        } catch (\Throwable) {
            // No FK (e.g. SQLite) or already dropped
        }

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->leftJoin('team_plan as tp', function ($j) {
                $j->on('tp.plan', '=', 'ag.plan')
                    ->on('tp.team', '=', 'a.slot_team');
            })
            ->whereNotNull('a.slot_team')
            ->select(['a.id', 'tp.team_number_plan'])
            ->get();

        foreach ($rows as $row) {
            DB::table('activity')->where('id', $row->id)->update([
                'slot_team' => $row->team_number_plan,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity') || ! Schema::hasColumn('activity', 'slot_team')) {
            return;
        }

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->leftJoin('team_plan as tp', function ($j) {
                $j->on('tp.plan', '=', 'ag.plan')
                    ->on('tp.team_number_plan', '=', 'a.slot_team');
            })
            ->whereNotNull('a.slot_team')
            ->select(['a.id', 'tp.team'])
            ->get();

        foreach ($rows as $row) {
            DB::table('activity')->where('id', $row->id)->update([
                'slot_team' => $row->team,
            ]);
        }

        Schema::table('activity', function (Blueprint $table) {
            $table->foreign('slot_team')->references('id')->on('team')->onDelete('cascade');
        });
    }
};
