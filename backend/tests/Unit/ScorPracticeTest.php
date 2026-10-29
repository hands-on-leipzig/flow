<?php

namespace Tests\Unit;

use App\Scor\ScorPractice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScorPracticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Practice sheet tests require sqlite.');
        }
        Schema::dropIfExists('event');
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
        });
        DB::table('event')->insert(['id' => 1, 'name' => 'Future Cup']);
    }

    public function test_practice_sheet_scores_without_storing_and_blocks_the_queen_bonus(): void
    {
        $practice = new ScorPractice();
        $sheet = $practice->sheet(1);
        $young = null;
        $bonus = null;
        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['label'] === 'Schlüsselarten im jungen Wald') {
                    $young = $item['id'];
                }
                if ($item['label'] === 'Bonus invasive Königin') {
                    $bonus = $item['id'];
                }
            }
        }

        $this->assertSame(60, $practice->score([$young => 3])['points']);

        $this->expectException(\App\Scor\ScorAccessException::class);
        $practice->score([$young => 2, $bonus => 'yes']);
    }
}
