<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('m_first_program', 'max_match_rounds')) {
            Schema::table('m_first_program', function (Blueprint $table) {
                $table->unsignedTinyInteger('max_match_rounds')->nullable()->after('sequence');
            });
        }

        // Challenge = 3, Future 8+ = 5; all others stay NULL.
        DB::table('m_first_program')->where('id', 3)->update(['max_match_rounds' => 3]);
        DB::table('m_first_program')->where('id', 8)->update(['max_match_rounds' => 5]);
        DB::table('m_first_program')
            ->whereNotIn('id', [3, 8])
            ->update(['max_match_rounds' => null]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('m_first_program', 'max_match_rounds')) {
            return;
        }

        Schema::table('m_first_program', function (Blueprint $table) {
            $table->dropColumn('max_match_rounds');
        });
    }
};
