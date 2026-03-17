<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. extra_block: add type enum (inserted, free, slot)
     * 2. slot_block_team: new table for team assignments to slot blocks
     * 3. activity: add slot_team for slot-block-assigned team
     */
    public function up(): void
    {
        // 1. extra_block: add type
        if (Schema::hasTable('extra_block') && !Schema::hasColumn('extra_block', 'type')) {
            Schema::table('extra_block', function (Blueprint $table) {
                $table->enum('type', ['inserted', 'free', 'slot'])->default('free')->after('active');
            });
            // Backfill: 'free' where insert_point is null, 'inserted' where insert_point is set
            DB::table('extra_block')->whereNull('insert_point')->update(['type' => 'free']);
            DB::table('extra_block')->whereNotNull('insert_point')->update(['type' => 'inserted']);
        }

        // 2. slot_block_team: new table
        if (!Schema::hasTable('slot_block_team')) {
            Schema::create('slot_block_team', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('extra_block');
                $table->unsignedInteger('team');
                $table->datetime('start');

                $table->unique(['extra_block', 'team']);
                $table->foreign('extra_block')->references('id')->on('extra_block')->onDelete('cascade');
                $table->foreign('team')->references('id')->on('team')->onDelete('cascade');
            });
        }

        // 3. activity: add slot_team
        if (Schema::hasTable('activity') && !Schema::hasColumn('activity', 'slot_team')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->unsignedInteger('slot_team')->nullable()->after('extra_block');
                $table->foreign('slot_team')->references('id')->on('team')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('activity') && Schema::hasColumn('activity', 'slot_team')) {
            Schema::table('activity', function (Blueprint $table) {
                $table->dropForeign(['slot_team']);
                $table->dropColumn('slot_team');
            });
        }

        Schema::dropIfExists('slot_block_team');

        if (Schema::hasTable('extra_block') && Schema::hasColumn('extra_block', 'type')) {
            Schema::table('extra_block', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
