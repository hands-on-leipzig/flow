<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('q_plan')) {
            return;
        }

        Schema::table('q_plan', function (Blueprint $table) {
            if (! Schema::hasColumn('q_plan', 'evaluation_status')) {
                $table->string('evaluation_status', 20)->default('ok')->after('calculated');
            }
            if (! Schema::hasColumn('q_plan', 'evaluation_reasons')) {
                $table->text('evaluation_reasons')->nullable()->after('evaluation_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('q_plan')) {
            return;
        }

        Schema::table('q_plan', function (Blueprint $table) {
            if (Schema::hasColumn('q_plan', 'evaluation_reasons')) {
                $table->dropColumn('evaluation_reasons');
            }
            if (Schema::hasColumn('q_plan', 'evaluation_status')) {
                $table->dropColumn('evaluation_status');
            }
        });
    }
};
