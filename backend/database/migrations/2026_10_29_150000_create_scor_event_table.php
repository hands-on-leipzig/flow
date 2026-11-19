<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scor_event')) {
            return;
        }

        Schema::create('scor_event', function (Blueprint $table) {
            $table->unsignedInteger('event')->primary();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('playground_reset_at')->nullable();
            $table->text('mail_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scor_event');
    }
};
