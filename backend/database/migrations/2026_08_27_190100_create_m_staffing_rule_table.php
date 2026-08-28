<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_staffing_rule')) {
            return;
        }

        Schema::create('m_staffing_rule', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('m_role');
            $table->unsignedSmallInteger('min');
            $table->unsignedSmallInteger('best');
            $table->unsignedSmallInteger('max');
            $table->text('ui_description')->nullable();

            $table->unique('m_role');
            $table->foreign('m_role')->references('id')->on('m_role')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_staffing_rule');
    }
};
