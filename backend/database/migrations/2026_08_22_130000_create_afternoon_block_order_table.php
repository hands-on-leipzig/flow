<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('afternoon_block_order')) {
            return;
        }

        Schema::create('afternoon_block_order', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedSmallInteger('sequence')->default(0);

            $table->unique(['plan', 'activity_type_detail']);
            $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            $table->foreign('activity_type_detail')->references('id')->on('m_activity_type_detail')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afternoon_block_order');
    }
};
