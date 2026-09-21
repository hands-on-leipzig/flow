<?php

namespace Tests\Unit;

use App\Support\ProgramCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgramCatalogOfficialNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('ProgramCatalog official name tests require sqlite.');
        }

        Schema::dropIfExists('m_first_program');
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->string('display_name')->nullable();
            $table->string('official_name')->nullable();
            $table->string('letter', 8)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        DB::table('m_first_program')->insert([
            [
                'id' => 3,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'official_name' => '<i>FIRST</i> LEGO League Challenge',
                'letter' => 'C',
                'sequence' => 2,
            ],
            [
                'id' => 99,
                'name' => 'ALPHA',
                'display_name' => 'Alpha',
                'official_name' => 'Some Brand <b>Alpha</b>',
                'letter' => 'A',
                'sequence' => 9,
            ],
        ]);
    }

    public function test_official_html_and_plain_from_catalog(): void
    {
        $this->assertSame(
            '<i>FIRST</i> LEGO League Challenge',
            ProgramCatalog::officialNameHtml('CHALLENGE')
        );
        $this->assertSame(
            'FIRST LEGO League Challenge',
            ProgramCatalog::officialNamePlain('CHALLENGE')
        );
    }

    public function test_non_fll_official_name_is_not_rewritten(): void
    {
        $this->assertSame('Some Brand <b>Alpha</b>', ProgramCatalog::officialNameHtml(99));
        $this->assertSame('Some Brand Alpha', ProgramCatalog::officialNamePlain('ALPHA'));
        $this->assertSame('Alpha', ProgramCatalog::displayName('ALPHA'));
    }
}
