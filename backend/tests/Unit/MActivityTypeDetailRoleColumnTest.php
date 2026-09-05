<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MActivityTypeDetailRoleColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('ATD role column tests require sqlite.');
        }

        Schema::dropAllTables();
        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
        });
    }

    public function test_role_column_is_nullable_foreign_key_to_m_role(): void
    {
        $migration = require database_path('migrations/2026_09_05_120000_add_role_to_m_activity_type_detail.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('m_activity_type_detail', 'role'));

        DB::table('m_role')->insert(['id' => 4, 'name' => 'Jury']);
        DB::table('m_activity_type_detail')->insert([
            ['id' => 1, 'name' => 'Free', 'activity_type' => 1, 'role' => null],
            ['id' => 2, 'name' => 'Judging', 'activity_type' => 1, 'role' => 4],
        ]);

        $this->assertNull(DB::table('m_activity_type_detail')->where('id', 1)->value('role'));
        $this->assertSame(4, (int) DB::table('m_activity_type_detail')->where('id', 2)->value('role'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('m_activity_type_detail', 'role'));
    }
}
