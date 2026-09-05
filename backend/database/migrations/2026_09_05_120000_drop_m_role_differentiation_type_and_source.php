<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_role')) {
            return;
        }

        $drop = [];
        if (Schema::hasColumn('m_role', 'differentiation_type')) {
            $drop[] = 'differentiation_type';
        }
        if (Schema::hasColumn('m_role', 'differentiation_source')) {
            $drop[] = 'differentiation_source';
        }
        if ($drop === []) {
            return;
        }

        Schema::table('m_role', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_role')) {
            return;
        }

        Schema::table('m_role', function (Blueprint $table) {
            if (! Schema::hasColumn('m_role', 'differentiation_type')) {
                $table->string('differentiation_type', 100)->nullable()->after('description');
            }
            if (! Schema::hasColumn('m_role', 'differentiation_source')) {
                $table->text('differentiation_source')->nullable()->after('differentiation_type');
            }
        });
    }
};
