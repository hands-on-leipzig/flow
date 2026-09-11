<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_notice')) {
            Schema::create('m_notice', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->string('key', 64)->unique();
                $table->string('kind', 16);
                $table->string('condition_key', 64)->nullable();
                $table->unsignedInteger('help_screen');
                $table->string('title', 255)->nullable();
                $table->text('body');
                $table->unsignedInteger('sort_order');
                $table->string('time_mode', 16)->nullable();
                $table->date('abs_start')->nullable();
                $table->date('abs_end')->nullable();
                $table->integer('rel_start_days')->nullable();
                $table->integer('rel_end_days')->nullable();

                $table->foreign('help_screen')
                    ->references('id')
                    ->on('m_help_screen')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('event_notice_hidden')) {
            Schema::create('event_notice_hidden', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('notice');

                $table->unique(['event', 'notice']);
                $table->foreign('event')
                    ->references('id')
                    ->on('event')
                    ->cascadeOnDelete();
                $table->foreign('notice')
                    ->references('id')
                    ->on('m_notice')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notice_hidden');
        Schema::dropIfExists('m_notice');
    }
};
