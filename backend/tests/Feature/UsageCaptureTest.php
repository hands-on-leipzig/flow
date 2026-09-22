<?php

namespace Tests\Feature;

use App\Models\DisplaySession;
use App\Models\PdfDownload;
use App\Services\PdfDownloadRecorder;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsageCaptureTest extends TestCase
{
    private const EVENT_ID = 7;

    private const DEVICE = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Usage capture tests require sqlite.');
        }

        $this->createSchema();
        DB::table('event')->insert([
            'id' => self::EVENT_ID,
            'name' => 'Test',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-09-22',
            'days' => 1,
        ]);
    }

    public function test_one_link_preview_inserts_zero_rows(): void
    {
        $this->postJson('/api/one-link-access', [
            'event_id' => self::EVENT_ID,
            'preview' => '1',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, DB::table('s_one_link_access')->count());
    }

    public function test_one_link_without_preview_inserts_one_row(): void
    {
        $this->postJson('/api/one-link-access', [
            'event_id' => self::EVENT_ID,
            'source' => 'direct',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('s_one_link_access')->count());
        $this->assertSame(self::EVENT_ID, (int) DB::table('s_one_link_access')->value('event'));
    }

    public function test_surface_form_volunteer_inserts_unknown_kind_does_not(): void
    {
        $this->postJson('/api/surface-access', [
            'event_id' => self::EVENT_ID,
            'kind' => 'form_volunteer',
        ])->assertOk();

        $this->assertSame(1, DB::table('s_surface_access')->count());
        $this->assertSame('form_volunteer', DB::table('s_surface_access')->value('kind'));

        $this->postJson('/api/surface-access', [
            'event_id' => self::EVENT_ID,
            'kind' => 'not_a_kind',
        ])->assertOk();

        $this->assertSame(1, DB::table('s_surface_access')->count());
    }

    public function test_display_heartbeat_resumes_within_gap_and_opens_new_session_after(): void
    {
        $this->postJson('/api/display-heartbeat', [
            'event_id' => self::EVENT_ID,
            'device_id' => self::DEVICE,
        ])->assertOk();

        $this->postJson('/api/display-heartbeat', [
            'event_id' => self::EVENT_ID,
            'device_id' => self::DEVICE,
        ])->assertOk();

        $this->assertSame(1, DisplaySession::query()->count());

        DisplaySession::query()->update([
            'last_seen' => Carbon::now()->subMinutes(16),
        ]);

        $this->postJson('/api/display-heartbeat', [
            'event_id' => self::EVENT_ID,
            'device_id' => self::DEVICE,
        ])->assertOk();

        $this->assertSame(2, DisplaySession::query()->count());
        $this->assertNotSame(
            DisplaySession::query()->orderBy('id')->value('id'),
            DisplaySession::query()->orderByDesc('id')->value('id'),
        );
    }

    public function test_pdf_recorder_allowed_kind_inserts_unknown_does_not(): void
    {
        $recorder = app(PdfDownloadRecorder::class);
        $recorder->record(self::EVENT_ID, 'overview_sheet', null);
        $this->assertSame(1, PdfDownload::query()->count());

        $recorder->record(self::EVENT_ID, 'plan_wifi', null);
        $this->assertSame(1, PdfDownload::query()->count());
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('s_one_link_access');
        Schema::dropIfExists('s_surface_access');
        Schema::dropIfExists('s_pdf_download');
        Schema::dropIfExists('s_display_session');
        Schema::dropIfExists('event');

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('regional_partner')->default(1);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('season')->default(1);
            $table->date('date');
            $table->unsignedTinyInteger('days')->default(1);
        });

        Schema::create('s_one_link_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->date('access_date');
            $table->timestamp('access_time')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referrer')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('accept_language', 50)->nullable();
            $table->unsignedSmallInteger('screen_width')->nullable();
            $table->unsignedSmallInteger('screen_height')->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->decimal('device_pixel_ratio', 3, 2)->nullable();
            $table->boolean('touch_support')->nullable();
            $table->string('connection_type', 20)->nullable();
            $table->string('source', 20)->nullable();
        });

        Schema::create('s_surface_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->string('kind', 32);
            $table->date('access_date');
            $table->timestamp('access_time')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referrer')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('accept_language', 50)->nullable();
            $table->unsignedSmallInteger('screen_width')->nullable();
            $table->unsignedSmallInteger('screen_height')->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->decimal('device_pixel_ratio', 3, 2)->nullable();
            $table->boolean('touch_support')->nullable();
            $table->string('connection_type', 20)->nullable();
            $table->string('source', 20)->nullable();
        });

        Schema::create('s_pdf_download', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('user')->nullable();
            $table->string('kind', 32);
            $table->timestamp('created')->nullable();
        });

        Schema::create('s_display_session', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->char('device_id', 36);
            $table->string('kind', 32);
            $table->timestamp('start');
            $table->timestamp('last_seen');
            $table->text('user_agent')->nullable();
            $table->string('ip_hash', 64)->nullable();
        });
    }
}
