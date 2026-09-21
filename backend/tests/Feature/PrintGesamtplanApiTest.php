<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrintGesamtplanApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Print gesamtplan API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        Schema::dropAllTables();
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
    }

    public function test_download_404_when_no_plan(): void
    {
        $this->postJson('/api/print/99/gesamtplan', [])
            ->assertStatus(404)
            ->assertExactJson(['error' => 'Plan not found']);
    }
}
