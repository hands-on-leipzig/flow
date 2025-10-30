<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add default values to table_event columns that are NOT NULL without defaults
        Schema::table('table_event', function (Blueprint $table) {
            $table->string('name', 100)->default('Unnamed Table')->change();
            $table->string('table_name', 100)->default('Unnamed Table')->change();
            $table->integer('table_number')->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove default values
        Schema::table('table_event', function (Blueprint $table) {
            $table->string('name', 100)->default(null)->change();
            $table->string('table_name', 100)->default(null)->change();
            $table->integer('table_number')->default(null)->change();
        });
    }
};