<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_match')) {
            return;
        }
        if (Schema::hasColumn('m_match', 'comment')) {
            return;
        }

        Schema::table('m_match', function (Blueprint $table) {
            // Plan-level note, denormalized onto every row for the plan key.
            $table->text('comment')->nullable()->after('tables');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_match') || ! Schema::hasColumn('m_match', 'comment')) {
            return;
        }

        Schema::table('m_match', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
