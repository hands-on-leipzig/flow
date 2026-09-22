<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('s_surface_access')) {
            Schema::create('s_surface_access', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('kind', 32);
                $table->date('access_date');
                $table->timestamp('access_time')->nullable();
                $table->text('user_agent')->nullable();
                $table->text('referrer')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('accept_language', 50)->nullable();
                $table->unsignedSmallInteger('screen_width')->nullable();
                $table->unsignedSmallInteger('screen_height')->nullable();
                $table->unsignedSmallInteger('viewport_width')->nullable();
                $table->unsignedSmallInteger('viewport_height')->nullable();
                $table->decimal('device_pixel_ratio', 3, 2)->nullable();
                $table->boolean('touch_support')->nullable();
                $table->string('connection_type', 20)->nullable();
                $table->string('source', 20)->nullable();

                $table->index('event', 's_surface_access_event_index');
                $table->index('access_date', 's_surface_access_access_date_index');
                $table->index(['event', 'kind'], 's_surface_access_event_kind_index');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('s_pdf_download')) {
            Schema::create('s_pdf_download', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('user')->nullable();
                $table->string('kind', 32);
                $table->timestamp('created')->nullable();

                $table->index('event');
                $table->index('user');
                $table->index('kind');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('user')->references('id')->on('user')->onDelete('set null');
            });
        }

        if (! Schema::hasTable('s_display_session')) {
            Schema::create('s_display_session', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->char('device_id', 36);
                $table->string('kind', 32);
                $table->timestamp('start');
                $table->timestamp('last_seen');
                $table->text('user_agent')->nullable();
                $table->string('ip_hash', 64)->nullable();

                $table->index(['event', 'device_id']);
                $table->index(['event', 'last_seen']);
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('s_display_session');
        Schema::dropIfExists('s_pdf_download');
        Schema::dropIfExists('s_surface_access');
    }
};
