<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_ceremonies')) {
            return;
        }

        Schema::create('m_ceremonies', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('activity_type_detail');
            $table->enum('kind', ['opening', 'awards']);
            $table->unsignedInteger('start_parameter')->nullable();
            $table->unsignedInteger('duration_parameter');

            $table->unique('activity_type_detail');
            $table->foreign('activity_type_detail')
                ->references('id')
                ->on('m_activity_type_detail')
                ->restrictOnDelete();
            $table->foreign('start_parameter')
                ->references('id')
                ->on('m_parameter')
                ->restrictOnDelete();
            $table->foreign('duration_parameter')
                ->references('id')
                ->on('m_parameter')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_ceremonies');
    }
};
