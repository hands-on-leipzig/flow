<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('m_parameter_condition', function (Blueprint $table) {
            $table->enum('action', ['show', 'hide', 'disable'])->default('show')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_parameter_condition', function (Blueprint $table) {
            $table->enum('action', ['show', 'hide'])->default('show')->change();
        });
    }
};
