<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('extra_block')
            ->where(function ($q) {
                $q->where('type', 'inserted');
                if (Schema::hasColumn('extra_block', 'insert_point')) {
                    $q->orWhereNotNull('insert_point');
                }
            })
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('activity')->whereIn('extra_block', $ids)->delete();
            DB::table('extra_block')->whereIn('id', $ids)->delete();
        }

        $insertedTypeIds = DB::table('m_activity_type_detail')
            ->whereIn('code', ['c_inserted_block', 'e_inserted_block', 'g_inserted_block'])
            ->pluck('id');

        if ($insertedTypeIds->isNotEmpty()) {
            DB::table('m_visibility')->whereIn('activity_type_detail', $insertedTypeIds)->delete();
            DB::table('activity')->whereIn('activity_type_detail', $insertedTypeIds)->delete();
            DB::table('activity_group')->whereIn('activity_type_detail', $insertedTypeIds)->delete();
            DB::table('m_activity_type_detail')->whereIn('id', $insertedTypeIds)->delete();
        }

        if (Schema::hasColumn('extra_block', 'insert_point')) {
            Schema::table('extra_block', function (Blueprint $table) {
                $table->dropForeign('extra_block_insert_point_foreign');
                $table->dropColumn('insert_point');
            });
        }

        $bufferColumns = array_values(array_filter(
            ['buffer_before', 'buffer_after'],
            fn (string $column) => Schema::hasColumn('extra_block', $column)
        ));
        if ($bufferColumns !== []) {
            Schema::table('extra_block', function (Blueprint $table) use ($bufferColumns) {
                $table->dropColumn($bufferColumns);
            });
        }

        Schema::dropIfExists('m_insert_point');

        DB::statement("ALTER TABLE extra_block MODIFY type ENUM('free','slot') NOT NULL DEFAULT 'free'");
    }

    public function down(): void
    {
        Schema::create('m_insert_point', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('ui_label')->nullable();
            $table->string('ui_description')->nullable();
        });

        DB::statement("ALTER TABLE extra_block MODIFY type ENUM('inserted','free','slot') NOT NULL DEFAULT 'free'");

        Schema::table('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('insert_point')->nullable();
            $table->unsignedInteger('buffer_before')->nullable();
            $table->unsignedInteger('buffer_after')->nullable();
            $table->foreign('insert_point')->references('id')->on('m_insert_point')->onDelete('cascade');
        });
    }
};
