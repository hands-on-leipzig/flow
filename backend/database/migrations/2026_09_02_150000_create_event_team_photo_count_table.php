<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_team_photo_count')) {
            Schema::create('event_team_photo_count', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('team');
                $table->string('bucket', 16);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->unique(['team', 'bucket'], 'event_team_photo_count_team_bucket_unique');
                $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_team_photo_count');
    }
};
