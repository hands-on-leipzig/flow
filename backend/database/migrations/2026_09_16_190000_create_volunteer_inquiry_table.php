<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('volunteer_inquiry')) {
            return;
        }

        Schema::create('volunteer_inquiry', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('volunteer_person')->nullable();
            $table->string('role', 255);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255);
            $table->string('mobile', 50)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('event', 'volunteer_inquiry_event_index');
            $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            $table->foreign('volunteer_person')
                ->references('id')
                ->on('volunteer_person')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_inquiry');
    }
};
