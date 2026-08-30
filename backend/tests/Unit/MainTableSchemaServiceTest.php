<?php

namespace Tests\Unit;

use App\Services\MainTableSchemaService;
use Mockery;
use Tests\TestCase;

class MainTableSchemaServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_is_allowed_table_uses_discovery(): void
    {
        /** @var MainTableSchemaService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MainTableSchemaService::class)->makePartial();
        $service->shouldReceive('discoverMTables')->andReturn(['m_level', 'm_season']);

        $this->assertTrue($service->isAllowedTable('m_level'));
        $this->assertFalse($service->isAllowedTable('users'));
        $this->assertFalse($service->isAllowedTable('m_nope'));
    }

    public function test_prepare_write_rejects_unknown_columns(): void
    {
        /** @var MainTableSchemaService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MainTableSchemaService::class)->makePartial();
        $service->shouldReceive('describeColumns')->andReturn([
            $this->col('id', 'int', nullable: false, autoIncrement: true),
            $this->col('name', 'varchar(50)', nullable: false),
        ]);
        $service->shouldReceive('getPrimaryKeyColumn')->andReturn('id');

        $result = $service->prepareWritePayload('m_level', [
            'name' => 'X',
            'hacker' => 'nope',
        ], true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unknown column', $result['error']);
    }

    public function test_prepare_write_coerces_empty_nullable_to_null(): void
    {
        /** @var MainTableSchemaService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MainTableSchemaService::class)->makePartial();
        $service->shouldReceive('describeColumns')->andReturn([
            $this->col('id', 'int', nullable: false, autoIncrement: true),
            $this->col('note', 'varchar(100)', nullable: true),
            $this->col('count', 'int', nullable: false),
        ]);
        $service->shouldReceive('getPrimaryKeyColumn')->andReturn('id');

        $result = $service->prepareWritePayload('m_x', [
            'note' => '',
            'count' => 0,
        ], true);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['data']['note']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertArrayNotHasKey('id', $result['data']);
    }

    public function test_prepare_write_rejects_empty_non_string_not_null(): void
    {
        /** @var MainTableSchemaService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MainTableSchemaService::class)->makePartial();
        $service->shouldReceive('describeColumns')->andReturn([
            $this->col('teams', 'int', nullable: false),
        ]);
        $service->shouldReceive('getPrimaryKeyColumn')->andReturn('id');

        $result = $service->prepareWritePayload('m_x', ['teams' => ''], true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NOT NULL', $result['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function col(
        string $name,
        string $sqlType,
        bool $nullable = true,
        bool $autoIncrement = false,
    ): array {
        return [
            'name' => $name,
            'sql_type' => $sqlType,
            'nullable' => $nullable,
            'default' => null,
            'extra' => $autoIncrement ? 'auto_increment' : '',
            'key' => $name === 'id' ? 'PRI' : '',
            'auto_increment' => $autoIncrement,
            'generated' => false,
            'max_length' => null,
            'unsigned' => false,
            'enum_values' => null,
            'is_set' => false,
            'is_enum' => false,
            'is_booleanish' => false,
            'unique' => false,
            'writable' => ! $autoIncrement,
            'restriction' => $sqlType,
        ];
    }
}
