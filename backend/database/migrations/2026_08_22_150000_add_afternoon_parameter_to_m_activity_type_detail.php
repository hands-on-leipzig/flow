<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional expert parameter embedded on an afternoon block.
     * NULL = no parameter on the box; otherwise m_parameter.id.
     */
    public function up(): void
    {
        if (Schema::hasColumn('m_activity_type_detail', 'afternoon_parameter')) {
            return;
        }

        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('afternoon_parameter')->nullable()->after('afternoon_default');
            $table->foreign('afternoon_parameter')->references('id')->on('m_parameter')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('m_activity_type_detail', 'afternoon_parameter')) {
            return;
        }

        Schema::table('m_activity_type_detail', function (Blueprint $table) {
            $table->dropForeign(['afternoon_parameter']);
            $table->dropColumn('afternoon_parameter');
        });
    }
};
