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
        if (! Schema::hasTable('extra_block') || ! Schema::hasColumn('extra_block', 'name')) {
            return;
        }

        Schema::table('extra_block', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('extra_block') || ! Schema::hasColumn('extra_block', 'name')) {
            return;
        }

        Schema::table('extra_block', function (Blueprint $table) {
            $table->string('name', 50)->nullable()->change();
        });
    }
};
