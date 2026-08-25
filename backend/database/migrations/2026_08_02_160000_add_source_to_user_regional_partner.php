<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_regional_partner', function (Blueprint $table) {
            if (!Schema::hasColumn('user_regional_partner', 'source')) {
                $table->string('source', 16)->default('draht')->after('regional_partner');
            }
            if (!Schema::hasColumn('user_regional_partner', 'granted_at')) {
                $table->timestamp('granted_at')->nullable()->after('source');
            }
            if (!Schema::hasColumn('user_regional_partner', 'granted_by')) {
                $table->unsignedInteger('granted_by')->nullable()->after('granted_at');
            }
        });

        // Deduplicate before unique index (keep lowest id)
        $duplicates = DB::table('user_regional_partner')
            ->select('user', 'regional_partner', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('user', 'regional_partner')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('user_regional_partner')
                ->where('user', $dup->user)
                ->where('regional_partner', $dup->regional_partner)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        try {
            Schema::table('user_regional_partner', function (Blueprint $table) {
                $table->unique(['user', 'regional_partner'], 'urp_user_rp_unique');
            });
        } catch (\Throwable) {
            // Unique index already present
        }
    }

    public function down(): void
    {
        try {
            Schema::table('user_regional_partner', function (Blueprint $table) {
                $table->dropUnique('urp_user_rp_unique');
            });
        } catch (\Throwable) {
            // ignore
        }

        Schema::table('user_regional_partner', function (Blueprint $table) {
            $cols = array_filter(['granted_by', 'granted_at', 'source'], fn ($c) => Schema::hasColumn('user_regional_partner', $c));
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
