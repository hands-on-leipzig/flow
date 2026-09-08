<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_help_topic')) {
            Schema::create('m_help_topic', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->string('key', 64)->unique();
                $table->string('name', 255);
                $table->unsignedInteger('sort_order');
            });
        }

        if (! Schema::hasTable('m_help_screen')) {
            Schema::create('m_help_screen', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->string('key', 64)->unique();
                $table->string('name', 255);
                $table->string('route_path', 255);
                $table->text('description')->nullable();
                $table->text('must_do')->nullable();
                $table->text('can_do')->nullable();
                $table->unsignedInteger('sort_order');
            });
        }

        if (! Schema::hasTable('m_help_action')) {
            Schema::create('m_help_action', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('help_screen');
                $table->unsignedInteger('help_topic');
                $table->string('title', 255);
                $table->unsignedInteger('sort_order');

                $table->foreign('help_screen')
                    ->references('id')
                    ->on('m_help_screen')
                    ->restrictOnDelete();
                $table->foreign('help_topic')
                    ->references('id')
                    ->on('m_help_topic')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('m_help_action_step')) {
            Schema::create('m_help_action_step', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('help_action');
                $table->text('body');
                $table->unsignedInteger('sort_order');

                $table->foreign('help_action')
                    ->references('id')
                    ->on('m_help_action')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_help_action_step');
        Schema::dropIfExists('m_help_action');
        Schema::dropIfExists('m_help_screen');
        Schema::dropIfExists('m_help_topic');
    }
};
