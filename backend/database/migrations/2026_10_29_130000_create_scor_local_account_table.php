<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scor_local_account')) {
            return;
        }

        Schema::create('scor_local_account', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('user');
            $table->string('username', 40);
            $table->string('password');
            $table->unique('username', 'scor_local_account_username_unique');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scor_local_account');
    }
};
