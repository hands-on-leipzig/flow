<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class VisibilityApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(KeycloakJwtMiddleware::class);

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Visibility API tests require sqlite.');
        }

        $this->createSchema();
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_matrix_includes_future_8_roles_and_name_short(): void
    {
        $response = $this->getJson('/api/visibility/matrix');

        $response->assertOk();
        $roles = collect($response->json('roles'));
        $f8 = $roles->firstWhere('id', 22);

        $this->assertNotNull($f8);
        $this->assertSame(8, (int) $f8['first_program']);
        $this->assertSame('F8 J', $f8['name_short']);
        $this->assertSame('FUTURE_8', $f8['program']);

        $roleProgramIds = collect($response->json('role_programs'))->pluck('id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();
        $this->assertContains(8, $roleProgramIds);
        $this->assertContains(3, $roleProgramIds);
        $this->assertContains(null, $roleProgramIds);
        $this->assertNotContains(1, $roleProgramIds);

        $activityProgramIds = collect($response->json('activity_type_programs'))->pluck('id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();
        $this->assertContains(2, $activityProgramIds);
        $this->assertContains(3, $activityProgramIds);
        $this->assertNotContains(7, $activityProgramIds);
    }

    public function test_reorder_is_scoped_to_one_program(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/visibility/roles/reorder', [
            'first_program' => 3,
            'order' => [
                ['id' => 5, 'sequence' => 1],
                ['id' => 4, 'sequence' => 2],
            ],
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame(2, (int) DB::table('m_role')->where('id', 4)->value('sequence'));
        $this->assertSame(1, (int) DB::table('m_role')->where('id', 5)->value('sequence'));
        $this->assertSame(1, (int) DB::table('m_role')->where('id', 9)->value('sequence'));
        $this->assertSame(4, (int) DB::table('m_role')->where('id', 22)->value('sequence'));
        $this->assertSame(1, (int) DB::table('m_role')->where('id', 14)->value('sequence'));
    }

    public function test_reorder_null_program_only_touches_overall_roles(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/visibility/roles/reorder', [
            'first_program' => null,
            'order' => [
                ['id' => 14, 'sequence' => 1],
                ['id' => 2, 'sequence' => 2],
            ],
        ])->assertOk();

        $this->assertSame(2, (int) DB::table('m_role')->where('id', 2)->value('sequence'));
        $this->assertSame(1, (int) DB::table('m_role')->where('id', 4)->value('sequence'));
        $this->assertSame(1, (int) DB::table('m_role')->where('id', 9)->value('sequence'));
    }

    public function test_reorder_rejects_roles_from_another_program(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/visibility/roles/reorder', [
            'first_program' => 3,
            'order' => [
                ['id' => 4, 'sequence' => 1],
                ['id' => 9, 'sequence' => 2],
            ],
        ])->assertStatus(422);
    }

    private function actingAsAdmin(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isFlowAdmin')->andReturn(true);
        $this->actingAs($user);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('m_visibility');
        Schema::dropIfExists('m_activity_type_detail');
        Schema::dropIfExists('m_activity_type');
        Schema::dropIfExists('m_role');
        Schema::dropIfExists('m_first_program');

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('letter')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('logo_stem')->nullable();
        });

        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('name_short')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_activity_type', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_visibility', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail')->nullable();
            $table->unsignedInteger('role')->nullable();
        });
    }

    private function seedCatalog(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => 1, 'name' => 'DISCOVER', 'display_name' => 'Discover', 'letter' => 'D', 'sequence' => 3, 'logo_stem' => null],
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'sequence' => 1, 'logo_stem' => 'fll_explore'],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'sequence' => 2, 'logo_stem' => 'fll_challenge'],
            ['id' => 7, 'name' => 'FUTURE_5', 'display_name' => 'Future 5+', 'letter' => 'F5', 'sequence' => 4, 'logo_stem' => null],
            ['id' => 8, 'name' => 'FUTURE_8', 'display_name' => 'Future 8+', 'letter' => 'F8', 'sequence' => 5, 'logo_stem' => 'fll_future8'],
        ]);

        DB::table('m_role')->insert([
            ['id' => 2, 'name' => 'Moderation', 'name_short' => null, 'sequence' => 3, 'first_program' => null],
            ['id' => 4, 'name' => 'Juror:in', 'name_short' => 'C J', 'sequence' => 1, 'first_program' => 3],
            ['id' => 5, 'name' => 'Schiedsrichter:in', 'name_short' => 'RG F', 'sequence' => 2, 'first_program' => 3],
            ['id' => 9, 'name' => 'Gutachter:in', 'name_short' => 'E G', 'sequence' => 1, 'first_program' => 2],
            ['id' => 14, 'name' => 'Publikum', 'name_short' => null, 'sequence' => 1, 'first_program' => null],
            ['id' => 22, 'name' => 'Juror:in', 'name_short' => 'F8 J', 'sequence' => 4, 'first_program' => 8],
        ]);

        DB::table('m_activity_type')->insert([
            ['id' => 2, 'name' => 'Jury', 'sequence' => 210, 'first_program' => 3],
            ['id' => 3, 'name' => 'Begutachtung', 'sequence' => 120, 'first_program' => 2],
            ['id' => 19, 'name' => 'Game', 'sequence' => 320, 'first_program' => 8],
        ]);

        DB::table('m_activity_type_detail')->insert([
            ['id' => 10, 'name' => 'Jurygespräch', 'activity_type' => 2, 'sequence' => 1, 'first_program' => 3],
            ['id' => 20, 'name' => 'Begutachtung', 'activity_type' => 3, 'sequence' => 1, 'first_program' => 2],
        ]);
    }
}
