<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Models\MHelpAction;
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
        $this->assertCount(7, $topics);
        $this->assertSame(['teams', 'ausgabe', 'helfer', 'ablauf', 'zusatzaktivitaeten', 'am-tag', 'allgemein'], array_column($topics, 'key'));
        $this->assertSame(['Teams', 'Ausgabe', 'Helfer:innen', 'Ablauf', 'Zusätzliche Aktivitäten', 'am Tag', 'Allgemein'], array_column($topics, 'name'));

        $screens = $response->json('screens');
        $this->assertSame(
            [
                'publish-distribution',
                'publish-logos',
                'teams-data',
                'teams-program',
                'rooms',
                'volunteers-people',
                'volunteers-roster',
                'volunteers-staffing',
                'live-check-in',
                'live-cockpit',
                'schedule-general',
                'schedule-integration',
                'schedule-times',
                'schedule-afternoon',
                'schedule-expert',
                'schedule-protected',
                'schedule-free',
                'schedule-slots',
            ],
            array_column($screens, 'key')
        );
        $this->assertSame('/plan/publish', $screens[0]['route_path']);
        $this->assertNull($screens[0]['description']);
        $this->assertSame('/plan/publish/logos', $screens[1]['route_path']);
        $this->assertSame('/plan/teams/:program', $screens[3]['route_path']);
        $this->assertSame('/plan/rooms', $screens[4]['route_path']);
        $this->assertSame('/plan/volunteers', $screens[5]['route_path']);
        $this->assertSame('/plan/volunteers/staffing', $screens[7]['route_path']);
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

        $this->getJson('/api/help/screens/teams-program')
            ->assertOk()
            ->assertJsonPath('key', 'teams-program')
            ->assertJsonPath('name', 'Details pro Team')
            ->assertJsonPath('route_path', '/plan/teams/:program')
            ->assertJsonPath('actions', []);

        $this->getJson('/api/help/screens/schedule-general')
            ->assertOk()
            ->assertJsonPath('key', 'schedule-general')
            ->assertJsonPath('name', 'Ablauf - Allgemein')
            ->assertJsonPath('route_path', '/plan/schedule')
            ->assertJsonPath('actions', []);

        $this->getJson('/api/help/screens/publish-logos')
            ->assertOk()
            ->assertJsonPath('key', 'publish-logos')
            ->assertJsonPath('name', 'Logos')
            ->assertJsonPath('route_path', '/plan/publish/logos')
            ->assertJsonPath('actions', []);

        $this->getJson('/api/help/screens/teams-explore')
            ->assertNotFound();

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

    public function test_admin_creates_action_assigns_and_counts(): void
    {
        $create = $this->postJson('/api/admin/help/actions', [
            'help_topic' => 2,
            'title' => 'X',
        ]);
        $create->assertCreated();
        $actionId = (int) $create->json('id');
        $this->assertSame([], $create->json('help_screen_ids'));
        $this->assertNull($create->json('body'));
        $this->assertSame(0, (int) $create->json('open_count'));
        $this->assertSame(0, (int) $create->json('helpful_yes'));
        $this->assertSame(0, (int) $create->json('helpful_no'));
        $this->assertArrayNotHasKey('steps', $create->json());

        $this->postJson('/api/admin/help/screens/1/actions', [
            'help_action' => $actionId,
        ])->assertCreated();

        $show = $this->getJson('/api/help/screens/publish-distribution');
        $show->assertOk();
        $this->assertCount(1, $show->json('actions'));
        $this->assertSame($actionId, (int) $show->json('actions.0.id'));
        $this->assertArrayHasKey('body', $show->json('actions.0'));
        $this->assertArrayNotHasKey('steps', $show->json('actions.0'));

        $this->postJson("/api/help/actions/{$actionId}/open")->assertOk();
        $open = $this->postJson("/api/help/actions/{$actionId}/open");
        $open->assertOk()->assertJsonPath('open_count', 2);

        $this->postJson("/api/admin/help/actions/{$actionId}/steps", ['body' => 'First'])
            ->assertStatus(405);

        $this->putJson("/api/admin/help/actions/{$actionId}", [
            'open_count' => 99,
        ])->assertStatus(422);
    }

    public function test_feedback_replaces_previous_vote(): void
    {
        $create = $this->postJson('/api/admin/help/actions', [
            'help_topic' => 2,
            'title' => 'Vote me',
        ]);
        $create->assertCreated();
        $actionId = (int) $create->json('id');

        $first = $this->postJson("/api/help/actions/{$actionId}/feedback", [
            'helpful' => true,
            'previous' => null,
        ]);
        $first->assertOk()
            ->assertJsonPath('helpful_yes', 1)
            ->assertJsonPath('helpful_no', 0);

        $switch = $this->postJson("/api/help/actions/{$actionId}/feedback", [
            'helpful' => false,
            'previous' => true,
        ]);
        $switch->assertOk()
            ->assertJsonPath('helpful_yes', 0)
            ->assertJsonPath('helpful_no', 1);

        $same = $this->postJson("/api/help/actions/{$actionId}/feedback", [
            'helpful' => false,
            'previous' => false,
        ]);
        $same->assertOk()
            ->assertJsonPath('helpful_yes', 0)
            ->assertJsonPath('helpful_no', 1);
    }

    public function test_cannot_delete_topic_that_still_has_actions(): void
    {
        MHelpAction::query()->create([
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
        Schema::dropIfExists('m_help_action_screen');
        Schema::dropIfExists('help_action_stat');
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
            $table->unsignedInteger('help_topic');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->unsignedInteger('sort_order');
            $table->foreign('help_topic')->references('id')->on('m_help_topic')->restrictOnDelete();
        });

        Schema::create('help_action_stat', function (Blueprint $table) {
            $table->unsignedInteger('help_action')->primary();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->foreign('help_action')->references('id')->on('m_help_action')->cascadeOnDelete();
        });

        Schema::create('m_help_action_screen', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('help_action');
            $table->unsignedInteger('help_screen');
            $table->unique(['help_action', 'help_screen']);
            $table->foreign('help_action')->references('id')->on('m_help_action')->cascadeOnDelete();
            $table->foreign('help_screen')->references('id')->on('m_help_screen')->restrictOnDelete();
        });
    }

    private function seedRows(): void
    {
        DB::table('m_help_topic')->insert([
            ['id' => 1, 'key' => 'teams', 'name' => 'Teams', 'sort_order' => 1],
            ['id' => 2, 'key' => 'ausgabe', 'name' => 'Ausgabe', 'sort_order' => 2],
            ['id' => 3, 'key' => 'helfer', 'name' => 'Helfer:innen', 'sort_order' => 3],
            ['id' => 4, 'key' => 'ablauf', 'name' => 'Ablauf', 'sort_order' => 4],
            ['id' => 8, 'key' => 'zusatzaktivitaeten', 'name' => 'Zusätzliche Aktivitäten', 'sort_order' => 5],
            ['id' => 5, 'key' => 'am-tag', 'name' => 'am Tag', 'sort_order' => 6],
            ['id' => 6, 'key' => 'allgemein', 'name' => 'Allgemein', 'sort_order' => 7],
        ]);

        DB::table('m_help_screen')->insert([
            ['id' => 1, 'key' => 'publish-distribution', 'name' => 'Öffentliche Seite', 'route_path' => '/plan/publish', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 1],
            ['id' => 20, 'key' => 'publish-logos', 'name' => 'Logos', 'route_path' => '/plan/publish/logos', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 1],
            ['id' => 2, 'key' => 'teams-data', 'name' => 'Teamdaten', 'route_path' => '/plan/teams/data', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 2],
            ['id' => 3, 'key' => 'teams-program', 'name' => 'Details pro Team', 'route_path' => '/plan/teams/:program', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 3],
            ['id' => 6, 'key' => 'volunteers-roster', 'name' => 'Helfer:innenliste', 'route_path' => '/plan/volunteers/roster', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 6],
            ['id' => 7, 'key' => 'rooms', 'name' => 'Räume', 'route_path' => '/plan/rooms', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 4],
            ['id' => 8, 'key' => 'volunteers-people', 'name' => 'Personen', 'route_path' => '/plan/volunteers', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 5],
            ['id' => 9, 'key' => 'volunteers-staffing', 'name' => 'Zuordnung', 'route_path' => '/plan/volunteers/staffing', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 7],
            ['id' => 10, 'key' => 'live-check-in', 'name' => 'Check-In App', 'route_path' => '/plan/live/check-in', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 8],
            ['id' => 11, 'key' => 'live-cockpit', 'name' => 'Cockpit App', 'route_path' => '/plan/live/cockpit', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 9],
            ['id' => 12, 'key' => 'schedule-general', 'name' => 'Ablauf - Allgemein', 'route_path' => '/plan/schedule', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 10],
            ['id' => 13, 'key' => 'schedule-integration', 'name' => 'Ablauf - Integration', 'route_path' => '/plan/schedule/integration', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 11],
            ['id' => 14, 'key' => 'schedule-times', 'name' => 'Ablauf - Zeiten', 'route_path' => '/plan/schedule/times', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 12],
            ['id' => 15, 'key' => 'schedule-afternoon', 'name' => 'Ablauf - Nachmittag', 'route_path' => '/plan/schedule/afternoon', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 13],
            ['id' => 16, 'key' => 'schedule-expert', 'name' => 'Ablauf - Expertenparameter', 'route_path' => '/plan/schedule/expert', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 14],
            ['id' => 17, 'key' => 'schedule-protected', 'name' => 'Ablauf - Geschützte Parameter', 'route_path' => '/plan/schedule/protected', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 15],
            ['id' => 18, 'key' => 'schedule-free', 'name' => 'Zusätzliche Aktivitäten - Freie Blöcke', 'route_path' => '/plan/schedule/free', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 16],
            ['id' => 19, 'key' => 'schedule-slots', 'name' => 'Zusätzliche Aktivitäten - Slot-Blöcke', 'route_path' => '/plan/schedule/slots', 'description' => null, 'must_do' => null, 'can_do' => null, 'sort_order' => 17],
        ]);
    }
}
