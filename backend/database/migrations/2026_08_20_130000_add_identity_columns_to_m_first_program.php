<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_first_program', function (Blueprint $table) {
            if (! Schema::hasColumn('m_first_program', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('m_first_program', 'letter')) {
                $table->string('letter', 8)->nullable()->after('display_name');
            }
            if (! Schema::hasColumn('m_first_program', 'family')) {
                $table->string('family', 32)->nullable()->after('letter');
            }
        });

        DB::table('m_first_program')->where('name', 'EXPLORE')->update([
            'display_name' => 'Explore',
            'letter' => 'E',
        ]);
        DB::table('m_first_program')->where('name', 'CHALLENGE')->update([
            'display_name' => 'Challenge',
            'letter' => 'C',
        ]);
        DB::table('m_first_program')->where('name', 'DISCOVER')->update([
            'display_name' => 'Discover',
            'letter' => 'D',
        ]);
        DB::table('m_first_program')->where('name', 'FUTURE_5')->update([
            'display_name' => 'Future 5+',
        ]);
        DB::table('m_first_program')->where('name', 'FUTURE_8')->update([
            'display_name' => 'Future 8+',
        ]);
    }

    public function down(): void
    {
        Schema::table('m_first_program', function (Blueprint $table) {
            foreach (['family', 'letter', 'display_name'] as $column) {
                if (Schema::hasColumn('m_first_program', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
