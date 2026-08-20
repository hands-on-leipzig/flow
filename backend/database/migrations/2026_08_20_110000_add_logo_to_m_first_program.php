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
            if (! Schema::hasColumn('m_first_program', 'logo')) {
                $table->string('logo')->nullable()->after('color_hex');
            }
        });

        DB::table('m_first_program')->where('name', 'EXPLORE')->update(['logo' => 'fll_explore']);
        DB::table('m_first_program')->where('name', 'CHALLENGE')->update(['logo' => 'fll_challenge']);
        DB::table('m_first_program')->where('name', 'FUTURE_8')->update(['logo' => 'fll_future8']);
    }

    public function down(): void
    {
        Schema::table('m_first_program', function (Blueprint $table) {
            if (Schema::hasColumn('m_first_program', 'logo')) {
                $table->dropColumn('logo');
            }
        });
    }
};
