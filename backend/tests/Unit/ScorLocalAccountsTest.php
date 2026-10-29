<?php

namespace Tests\Unit;

use App\Models\User;
use App\Scor\ScorLocalAccounts;
use App\Support\ScorGrant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScorLocalAccountsTest extends TestCase
{
    private ScorLocalAccounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Local account tests require sqlite.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Schema::dropIfExists('scor_local_account');
        Schema::dropIfExists('event_user_access');
        Schema::dropIfExists('user_regional_partner');
        Schema::dropIfExists('event');
        Schema::dropIfExists('user');
        Schema::create('user', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('subject')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('regional_partner')->nullable();
        });
        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('regional_partner');
            $table->string('source')->default('draht');
        });
        Schema::create('event_user_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('event');
            $table->boolean('can_flow')->default(false);
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->unsignedInteger('granted_by')->nullable();
        });
        Schema::create('scor_local_account', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('user');
            $table->string('username');
            $table->string('password');
        });
        DB::table('user')->insert(['id' => 1, 'subject' => 'rp', 'name' => 'RP', 'email' => 'rp@example.test']);
        DB::table('event')->insert(['id' => 1, 'regional_partner' => 1]);
        DB::table('event_user_access')->insert([
            'user' => 1,
            'event' => 1,
            'can_scor' => true,
            'can_scor_admin' => true,
        ]);
        $this->accounts = new ScorLocalAccounts();
    }

    public function test_local_account_logs_in_without_a_hero_user(): void
    {
        $actor = new User;
        $actor->id = 1;
        $created = $this->accounts->create($actor, 1, 'Schiri 1', ScorGrant::fromFlags(true, false, false));

        $this->assertSame('Schiri 1', $created['username']);
        $this->assertNotSame('', $created['password']);
        $this->assertNull(DB::table('user')->where('name', 'Schiri 1')->value('email'));
        $this->assertStringStartsWith('scor-local-', (string) DB::table('user')->where('name', 'Schiri 1')->value('subject'));

        $login = $this->accounts->login('schiri 1', $created['password']);
        $claims = $this->accounts->claims($login['token']);
        $this->assertSame(1, $claims['event']);
        $this->assertSame(
            (int) DB::table('user')->where('name', 'Schiri 1')->value('id'),
            $claims['user'],
        );

        $this->expectException(\App\Scor\ScorAccessException::class);
        $this->accounts->login('Schiri 1', 'falsch');
    }
}
