<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scor_award')) {
            return;
        }

        Schema::create('scor_award', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('team')->nullable();
            $table->string('category', 32);
            $table->string('kind', 16);
            $table->unique(['event', 'team', 'category', 'kind'], 'scor_award_event_team_category_kind');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scor_award');
    }
};
