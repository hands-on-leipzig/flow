<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('m_role', function (Blueprint $table) {
            $table->boolean('pdf_export')->default(0)->after('preview_matrix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_role', function (Blueprint $table) {
            $table->dropColumn('pdf_export');
        });
    }
};