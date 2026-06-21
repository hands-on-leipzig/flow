<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add slug prefix columns to regional_partner
        Schema::table('regional_partner', function (Blueprint $table) {
            $table->string('slug_long', 100)->nullable()->after('dolibarr_id')
                ->comment('Langer Slug-Präfix, z.B. "muenchen" oder "leipzig-halle"');
            $table->string('slug_short', 20)->nullable()->after('slug_long')
                ->comment('Kurzes Kürzel, z.B. KFZ-Kennzeichen "m" oder manuell gesetzt "lhal"');
        });

        // Create event_slug table
        Schema::create('event_slug', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();

            $table->string('slug', 255)->notNull()
                ->comment('Der tatsächliche URL-Pfad, z.B. "muenchen-explore" oder "me"');

            $table->unsignedInteger('event_id')->notNull();
            $table->unsignedInteger('season_id')->notNull();

            $table->enum('program', ['explore', 'challenge', 'future', 'joint'])->notNull()
                ->comment('explore/challenge/future = programm-spezifisch; joint = deckt alle Programme ab');

            $table->enum('variant', ['long', 'short'])->notNull()->default('long')
                ->comment('long = voller Name ("muenchen-explore"), short = Kürzel ("me")');

            $table->boolean('is_primary')->notNull()->default(false)
                ->comment('Der Haupt-Slug, der im UI angezeigt und im QR-Code verwendet wird');

            $table->timestamps();

            // slug ist unique pro Saison (gleicher slug kann in neuer Saison auf anderes event zeigen)
            $table->unique(['slug', 'season_id'], 'ux_slug_season');
            $table->index('event_id', 'ix_event_id');
            $table->index('season_id', 'ix_season_id');
            $table->index(['event_id', 'is_primary'], 'ix_event_primary');

            $table->foreign('event_id')->references('id')->on('event')->onDelete('cascade');
            $table->foreign('season_id')->references('id')->on('m_season')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_slug');

        Schema::table('regional_partner', function (Blueprint $table) {
            $table->dropColumn(['slug_long', 'slug_short']);
        });
    }
};
