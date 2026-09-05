<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'slug_manual')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('slug_manual')->default(false)->after('slug');
            });
        }

        if (! Schema::hasTable('event_slug_history')) {
            Schema::create('event_slug_history', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('season');
                $table->string('slug', 255);
                $table->timestamp('replaced_at')->nullable();

                $table->unique(['slug', 'season'], 'event_slug_history_slug_season_unique');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            });
        }

        if (! Schema::hasIndex('event', 'event_slug_season_unique')) {
            $duplicates = DB::table('event')
                ->select('slug', 'season', DB::raw('COUNT(*) as total'))
                ->whereNotNull('slug')
                ->where('slug', '<>', '')
                ->groupBy('slug', 'season')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            // Renaming a live slug would break printed QR codes, so duplicates are
            // reported and the index is left out until they are resolved by hand.
            if ($duplicates->isEmpty()) {
                Schema::table('event', function (Blueprint $table) {
                    $table->unique(['slug', 'season'], 'event_slug_season_unique');
                });
            } else {
                Log::warning('event_slug_season_unique not created: duplicate slugs per season', [
                    'duplicates' => $duplicates->toArray(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('event', 'event_slug_season_unique')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropUnique('event_slug_season_unique');
            });
        }

        Schema::dropIfExists('event_slug_history');

        if (Schema::hasColumn('event', 'slug_manual')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn('slug_manual');
            });
        }
    }
};
