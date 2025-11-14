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
        Schema::table('user', function (Blueprint $table) {
            if (!Schema::hasColumn('user', 'name')) {
                $table->string('name', 255)->nullable()->after('subject');
            }
            if (!Schema::hasColumn('user', 'email')) {
                $table->string('email', 255)->nullable()->after('name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            if (Schema::hasColumn('user', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('user', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
