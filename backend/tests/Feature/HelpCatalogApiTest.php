<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Models\MHelpAction;
use App\Models\MHelpActionStep;
use App\Models\MHelpScreen;
use App\Models\MHelpTopic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HelpCatalogApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Help catalog API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        DB::statement('PRAGMA foreign_keys = ON');
        $this->createSchema();
        $this->seedRows();
    }

    public function test_catalog_returns_sorted_topics_screens_and_empty_actions(): void
    {
        $response = $this->getJson('/api/help/catalog');

        $response->assertOk();
        $topics = $response->json('topics');
        $this->assertCount(6, $topics);
        $this->assertSame(['teams', 'ausgabe', 'helfer', 'ablauf', 'am-tag', 'allgemein'], array_column($topics, 'key'));
        $this->assertSame(['Teams', 'Ausgabe', 'Helfer:innen', 'Ablauf', 'am Tag', 'Allgemein'], array_column($topics, 'name'));

        $screens = $response->json('screens');
        $this->assertCount(5, $screens);
        $this->assertSame('publish-distribution', $screens[0]['key']);
        $this->assertSame('/plan/publish', $screens[0]['route_path']);
        $this->assertNull($screens[0]['description']);
        $this->assertSame('teams-future_8', $screens[4]['key']);
        $this->assertSame([], $response->json('actions'));
    }

    public function test_screen_by_key_and_unknown_key_404(): void
    {
        $this->getJson('/api/help/screens/teams-data')
            ->assertOk()
            ->assertJsonPath('key', 'teams-data')
            ->assertJsonPath('name', 'Teamdaten')
            ->assertJsonPath('route_path', '/plan/teams/data')
            ->assertJsonPath('actions', []);

        $this->getJson('/api/help/screens/nope')
            ->assertNotFound()
            ->assertJson(['error' => 'Not found']);
    }

    public function test_admin_routes_reject_unauthenticated_when_middleware_runs(): void
    {
        $this->withMiddleware(KeycloakJwtMiddleware::class);

        $this->getJson('/api/admin/help/topics')
            ->assertUnauthorized();
    }

    public function test_admin_cannot_post_a_new_screen(): void
    {
        $this->postJson('/api/admin/help/screens', [
            'key' => 'extra',
            'name' => 'Extra',
            'route_path' => '/plan/extra',
        ])->assertStatus(405);
    }

    public function test_admin_can_put_description_but_not_key(): void
    {
        $this->putJson('/api/admin/help/screens/1', [
            'description' => 'Public page',
            'must_do' => '',
            'can_do' => 'Turn things on',
        ])->assertOk()
            ->assertJsonPath('description', 'Public page')
            ->assertJsonPath('must_do', null)
            ->assertJsonPath('can_do', 'Turn things on')
            ->assertJsonPath('key', 'publish-distribution');

        $this->putJson('/api/admin/help/screens/1', [
            'key' => 'hacked',
            'description' => 'x',
        ])->assertStatus(422);
    }

    public function test_admin_can_create_action_step_and_reorder(): void
    {
        $create = $this->postJson('/api/admin/help/actions', [
            'help_screen' => 1,
            'help_topic' => 2,
            'title' => 'Wie kann ich veröffentlichen?',
        ]);
        $create->assertCreated();
        $actionId = (int) $create->json('id');
        $this->assertSame(2, (int) $create->json('help_topic'));
        $this->assertSame('ausgabe', $create->json('topic.key'));
        $this->assertSame('publish-distribution', $create->json('screen.key'));

        $stepA = $this->postJson("/api/admin/help/actions/{$actionId}/steps", ['body' => 'First']);
        $stepB = $this->postJson("/api/admin/help/actions/{$actionId}/steps", ['body' => 'Second']);
        $stepA->assertCreated();
        $stepB->assertCreated();
        $idA = (int) $stepA->json('id');
        $idB = (int) $stepB->json('id');

        $this->postJson("/api/admin/help/actions/{$actionId}/steps/reorder", [
            'ids' => [$idB, $idA],
        ])->assertOk();

        $this->assertSame(1, (int) MHelpActionStep::query()->findOrFail($idB)->sort_order);
        $this->assertSame(2, (int) MHelpActionStep::query()->findOrFail($idA)->sort_order);

        $second = $this->postJson('/api/admin/help/actions', [
            'help_screen' => 1,
            'help_topic' => 2,
            'title' => 'Wie kann ich Logos setzen?',
        ]);
        $second->assertCreated();
        $id2 = (int) $second->json('id');

        $this->postJson('/api/admin/help/actions/reorder', [
            'help_screen' => 1,
            'ids' => [$id2, $actionId],
        ])->assertOk();

        $this->assertSame(1, (int) MHelpAction::query()->findOrFail($id2)->sort_order);
        $this->assertSame(2, (int) MHelpAction::query()->findOrFail($actionId)->sort_order);

        $catalog = $this->getJson('/api/help/catalog');
        $catalog->assertOk();
        $this->assertCount(2, $catalog->json('actions'));
        $this->assertSame($id2, (int) $catalog->json('actions.0.id'));
        $this->assertSame(['Second', 'First'], array_column($catalog->json('actions.1.steps'), 'body'));
    }

    public function test_cannot_delete_topic_that_still_has_actions(): void
    {
        MHelpAction::query()->create([
            'help_screen' => 1,
            'help_topic' => 2,
            'title' => 'Keep me',
            'sort_order' => 1,
        ]);

        $this->deleteJson('/api/admin/help/topics/2')
            ->assertStatus(409)
            ->assertJson(['error' => 'Topic still has actions']);

        $this->assertTrue(MHelpTopic::query()->where('id', 2)->exists());
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('m_help_action_step');
        Schema::dropIfExists('m_help_action');
        Schema::dropIfExists('m_help_screen');
        Schema::dropIfExists('m_help_topic');

        Schema::create('m_help_topic', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('key', 64)->unique();
            $table->string('name', 255);
            $table->unsignedInteger('sort_order');
        });

        Schema::create('m_help_screen', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('key', 64)->unique();
            $table->string('name', 255);
            $table->string('route_path', 255);
            $table->text('description')->nullable();
            $table->text('must_do')->nullable();
            $table->text('can_do')->nullable();
            $table->unsignedInteger('sort_order');
        });

        Schema::create('m_help_action', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('help_screen');
            $table->unsignedInteger('help_topic');
            $table->string('title', 255);
            $table->unsignedInteger('sort_order');
            $table->foreign('help_screen')->references('id')->on('m_help_screen')->restrictOnDelete();
            $table->foreign('help_topic')->references('id')->on('m_help_topic')->restrictOnDelete();
        });

        Schema::create('m_help_action_step', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('help_action');
            $table->text('body');
            $table->unsignedInteger('sort_order');
            $table->foreign('help_action')->references('id')->on('m_help_action')->cascadeOnDelete();
        });
    }

    private function seedRows(): void
    {
        DB::table('m_help_topic')->insert([
            ['id' => 1, 'key' => 'teams', 'name' => 'Teams', 'sort_order' => 1],
            ['id' => 2, 'key' => 'ausgabe', 'name' => 'Ausgabe', 'sort_order' => 2],
            ['id' => 3, 'key' => 'helfer', 'name' => 'Helfer:innen', 'sort_order' => 3],
            ['id' => 4, 'key' => 'ablauf', 'name' => 'Ablauf', 'sort_order' => 4],
            ['id' => 5, 'key' => 'am-tag', 'name' => 'am Tag', 'sort_order' => 5],
            ['id' => 6, 'key' => 'allgemein', 'name' => 'Allgemein', 'sort_order' => 6],
        ]);

        DB::table('m_help_screen')->insert([
            ['id' => 1, 'key' => 'publish-distribution', 'name' => 'Veröffentlichung', 'route_path' => '/plan/publish', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 1],
            ['id' => 2, 'key' => 'teams-data', 'name' => 'Teamdaten', 'route_path' => '/plan/teams/data', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 2],
            ['id' => 3, 'key' => 'teams-explore', 'name' => 'Details pro Team', 'route_path' => '/plan/teams/explore', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 3],
            ['id' => 4, 'key' => 'teams-challenge', 'name' => 'Details pro Team', 'route_path' => '/plan/teams/challenge', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 4],
            ['id' => 5, 'key' => 'teams-future_8', 'name' => 'Details pro Team', 'route_path' => '/plan/teams/future_8', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 5],
        ]);
    }
}
