<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Services\MainTableSchemaService;
use Tests\TestCase;

class MainTablesAdminApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
    }

    public function test_schema_returns_404_for_disallowed_table(): void
    {
        $this->mock(MainTableSchemaService::class, function ($mock) {
            $mock->shouldReceive('isAllowedTable')->with('users')->andReturn(false);
        });

        $this->getJson('/api/admin/main-tables/users/schema')
            ->assertNotFound()
            ->assertJson(['error' => 'Table not allowed']);
    }

    public function test_store_rejects_unknown_columns(): void
    {
        $this->mock(MainTableSchemaService::class, function ($mock) {
            $mock->shouldReceive('isAllowedTable')->with('m_level')->andReturn(true);
            $mock->shouldReceive('prepareWritePayload')
                ->once()
                ->andReturn(['ok' => false, 'error' => 'Unknown column: hacker']);
        });

        $this->postJson('/api/admin/main-tables/m_level', ['name' => 'X', 'hacker' => 1])
            ->assertStatus(422)
            ->assertJson(['error' => 'Unknown column: hacker']);
    }

    public function test_destroy_returns_409_when_restrict_blockers_exist(): void
    {
        $this->mock(MainTableSchemaService::class, function ($mock) {
            $mock->shouldReceive('isAllowedTable')->with('m_level')->andReturn(true);
            $mock->shouldReceive('deleteImpact')
                ->with('m_level', '1')
                ->andReturn([
                    'can_delete' => false,
                    'blockers' => [
                        [
                            'table' => 'm_parameter',
                            'column' => 'level',
                            'count' => 3,
                            'delete_rule' => 'RESTRICT',
                        ],
                    ],
                    'cascade_impact' => [],
                ]);
            $mock->shouldReceive('getPrimaryKeyColumn')->never();
        });

        $this->deleteJson('/api/admin/main-tables/m_level/1')
            ->assertStatus(409)
            ->assertJsonPath('error', 'Delete blocked by foreign key references')
            ->assertJsonPath('blockers.0.table', 'm_parameter');
    }

    public function test_get_count_requires_allowlist(): void
    {
        $this->mock(MainTableSchemaService::class, function ($mock) {
            $mock->shouldReceive('isAllowedTable')->with('users')->andReturn(false);
        });

        $this->getJson('/api/admin/main-tables/users/count')
            ->assertNotFound()
            ->assertJson(['error' => 'Table not allowed']);
    }

    public function test_import_route_removed(): void
    {
        // Former dedicated import route is gone; POST …/import hits store/{table}=import.
        $this->mock(MainTableSchemaService::class, function ($mock) {
            $mock->shouldReceive('isAllowedTable')->with('import')->andReturn(false);
        });

        $this->postJson('/api/admin/main-tables/import', [])
            ->assertNotFound()
            ->assertJson(['error' => 'Table not allowed']);
    }
}