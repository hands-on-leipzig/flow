<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('q_plan_team')) {
            return;
        }

        if (! Schema::hasColumn('q_plan_team', 'q1_transition_5_6')) {
            Schema::table('q_plan_team', function (Blueprint $table) {
                $table->decimal('q1_transition_5_6', 8, 2)->default(0)->after('q1_transition_4_5');
            });
        }
        if (! Schema::hasColumn('q_plan_team', 'q1_transition_6_7')) {
            Schema::table('q_plan_team', function (Blueprint $table) {
                $table->decimal('q1_transition_6_7', 8, 2)->default(0)->after('q1_transition_5_6');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('q_plan_team')) {
            return;
        }

        Schema::table('q_plan_team', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('q_plan_team', 'q1_transition_6_7')) {
                $drop[] = 'q1_transition_6_7';
            }
            if (Schema::hasColumn('q_plan_team', 'q1_transition_5_6')) {
                $drop[] = 'q1_transition_5_6';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
