<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Services\EventSlugService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class EventSlugServiceTest extends TestCase
{
    private const CURRENT_SEASON = 3;

    private const PAST_SEASON = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('EventSlugService tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->createSchema();
    }

    public function test_suggests_event_name_for_regional_event(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);

        $this->assertSame('aachen', $this->service()->suggest($event));
    }

    public function test_regional_slugs_get_program_suffixes_when_partner_runs_several(): void
    {
        $first = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->insertProgram(1, 1, 2, 1001);
        $this->insertEvent(['id' => 2, 'name' => 'Aachen', 'level' => 1]);

        $this->assertSame('aachen-explore', $this->service()->suggest($first->refresh()));
    }

    public function test_suggests_quali_from_the_part_after_the_first_dash(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'West - Köln', 'level' => 2]);

        $this->assertSame('quali-koeln', $this->service()->suggest($event));
    }

    public function test_suggests_finale_without_region(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Finale Deutschland', 'level' => 3]);

        $this->assertSame('finale', $this->service()->suggest($event));
    }

    public function test_generated_slug_gets_a_suffix_when_taken_in_the_same_season(): void
    {
        $taken = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($taken);

        $other = $this->insertEvent(['id' => 2, 'name' => 'Aachen', 'level' => 1, 'regional_partner' => 9]);

        $this->assertSame('aachen-2', $this->service()->ensure($other));
    }

    public function test_same_slug_may_be_reused_in_another_season(): void
    {
        $current = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($current);

        $past = $this->insertEvent(['id' => 2, 'name' => 'Aachen', 'level' => 1, 'season' => self::PAST_SEASON]);

        $this->assertSame('aachen', $this->service()->ensure($past));
    }

    public function test_manual_slug_is_kept_when_regenerating(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->assign($event, 'fll-aachen', true);

        $event->name = 'Aachen Neu';
        $event->save();

        $this->assertSame('fll-aachen', $this->service()->regenerate($event));
    }

    public function test_generated_slug_follows_a_renamed_event_and_keeps_the_old_one_redirectable(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($event);

        $event->name = 'Duesseldorf';
        $event->save();

        $this->assertSame('duesseldorf', $this->service()->regenerate($event));

        $match = $this->service()->find('aachen');
        $this->assertNotNull($match);
        $this->assertSame(1, (int) $match['event']->id);
        $this->assertSame('/duesseldorf', $match['redirect_to']);
    }

    public function test_manual_slug_is_rejected_when_already_used_in_the_season(): void
    {
        $taken = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($taken);

        $other = $this->insertEvent(['id' => 2, 'name' => 'Koeln', 'level' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->assign($other, 'aachen', true);
    }

    public function test_reserved_and_numeric_slugs_are_rejected(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->assign($event, 'plan', true);
    }

    public function test_numeric_slug_is_reserved_for_the_season_prefix(): void
    {
        $this->assertTrue($this->service()->isReserved('2025'));
        $this->assertFalse($this->service()->isReserved('aachen'));
    }

    public function test_current_season_url_has_no_year_and_past_seasons_do(): void
    {
        $current = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($current);

        $past = $this->insertEvent(['id' => 2, 'name' => 'Aachen', 'level' => 1, 'season' => self::PAST_SEASON]);
        $this->service()->ensure($past);

        $this->assertSame('https://handson.tools/aachen', $this->service()->url($current));
        $this->assertSame('https://handson.tools/2025/aachen', $this->service()->url($past));
    }

    public function test_lookup_without_year_uses_the_current_season(): void
    {
        $current = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->service()->ensure($current);
        $past = $this->insertEvent(['id' => 2, 'name' => 'Aachen', 'level' => 1, 'season' => self::PAST_SEASON]);
        $this->service()->ensure($past);

        $this->assertSame(1, (int) $this->service()->find('aachen')['event']->id);
        $this->assertSame(2, (int) $this->service()->find('aachen', 2025)['event']->id);
    }

    public function test_resolves_the_same_event_from_both_draht_ids(): void
    {
        $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->insertProgram(1, 1, 2, 1001);
        $this->insertProgram(2, 1, 3, 1002);

        $service = $this->service();

        $this->assertSame(1, (int) $service->resolve('draht', 1001)->id);
        $this->assertSame(1, (int) $service->resolve('draht', 1002)->id);
        // JOIN has no ids of its own; it addresses events through DRAHT.
        $this->assertSame(1, (int) $service->resolve('join', 1002)->id);
        $this->assertSame(1, (int) $service->resolve('flow', 1)->id);
        $this->assertNull($service->resolve('draht', 4242));
        $this->assertNull($service->resolve('contao', 1));
    }

    public function test_describe_reports_both_draht_ids_and_the_manual_flag(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->insertProgram(1, 1, 2, 1001);
        $this->insertProgram(2, 1, 3, 1002);
        $this->service()->assign($event->refresh(), 'aachen', true);

        $described = $this->service()->describe($event->refresh());

        $this->assertSame('aachen', $described['slug']);
        $this->assertSame('https://handson.tools/aachen', $described['url']);
        $this->assertSame([1001, 1002], $described['draht_ids']);
        $this->assertTrue($described['manual']);
        $this->assertTrue($described['current_season']);
        $this->assertSame(2026, $described['season_year']);
    }

    private function service(): EventSlugService
    {
        // Fresh instance per call: season lookups are memoized.
        return new EventSlugService();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertEvent(array $attributes): Event
    {
        $event = Event::query()->create(array_merge([
            'name' => 'Event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => self::CURRENT_SEASON,
            'date' => '2026-08-24',
            'days' => 1,
        ], $attributes));

        return $event->refresh();
    }

    private function insertProgram(int $id, int $event, int $firstProgram, ?int $drahtId): void
    {
        DB::table('event_program')->insert([
            'id' => $id,
            'event' => $event,
            'first_program' => $firstProgram,
            'draht_id' => $drahtId,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('event_slug_history');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_season');

        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('year');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('letter')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex')->nullable();
            $table->string('logo_stem')->nullable();
            $table->string('logo_white')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('slug_manual')->default(false);
            $table->unsignedInteger('regional_partner')->default(1);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('season')->default(1);
            $table->date('date');
            $table->unsignedTinyInteger('days')->default(1);
            $table->string('link')->nullable();
            $table->text('qrcode')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('event_slug_history', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('season');
            $table->string('slug');
            $table->timestamp('replaced_at')->nullable();

            $table->unique(['slug', 'season']);
        });

        DB::table('m_season')->insert([
            ['id' => self::PAST_SEASON, 'name' => 'Saison 2025', 'year' => 2025],
            ['id' => self::CURRENT_SEASON, 'name' => 'Saison 2026', 'year' => 2026],
        ]);

        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'sequence' => 2],
        ]);
    }
}
