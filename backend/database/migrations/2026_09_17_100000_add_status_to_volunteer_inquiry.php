<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('volunteer_inquiry')) {
            return;
        }

        Schema::table('volunteer_inquiry', function (Blueprint $table) {
            if (! Schema::hasColumn('volunteer_inquiry', 'status')) {
                $table->string('status', 20)->default('pending')->after('message');
            }
            if (! Schema::hasColumn('volunteer_inquiry', 'decided_at')) {
                $table->timestamp('decided_at')->nullable()->after('status');
            }
        });

        if (Schema::hasColumn('volunteer_inquiry', 'status')) {
            DB::table('volunteer_inquiry')
                ->whereNotNull('volunteer_person')
                ->where('status', 'pending')
                ->update([
                    'status' => 'accepted',
                    'decided_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('volunteer_inquiry')) {
            return;
        }

        Schema::table('volunteer_inquiry', function (Blueprint $table) {
            if (Schema::hasColumn('volunteer_inquiry', 'decided_at')) {
                $table->dropColumn('decided_at');
            }
            if (Schema::hasColumn('volunteer_inquiry', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
