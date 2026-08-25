<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copy each environment's event_explore / event_challenge / contao_*
     * into event_program, then drop those columns. Safe to re-run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program');
                $table->unsignedInteger('draht_id')->nullable();
                $table->unsignedInteger('contao_id')->nullable();

                $table->unique(['event', 'first_program'], 'event_program_event_program_unique');
                $table->index('draht_id');

                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict');
            });
        }

        if (Schema::hasColumn('event', 'event_explore') || Schema::hasColumn('event', 'event_challenge')) {
            $events = DB::table('event')->get();

            foreach ($events as $event) {
                $this->copyProgram(
                    (int) $event->id,
                    2,
                    Schema::hasColumn('event', 'event_explore') ? ($event->event_explore ?? null) : null,
                    Schema::hasColumn('event', 'contao_id_explore') ? ($event->contao_id_explore ?? null) : null,
                );
                $this->copyProgram(
                    (int) $event->id,
                    3,
                    Schema::hasColumn('event', 'event_challenge') ? ($event->event_challenge ?? null) : null,
                    Schema::hasColumn('event', 'contao_id_challenge') ? ($event->contao_id_challenge ?? null) : null,
                );
            }
        }

        foreach (['event_explore', 'event_challenge', 'contao_id_explore', 'contao_id_challenge'] as $column) {
            if (Schema::hasColumn('event', $column)) {
                Schema::table('event', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function copyProgram(int $eventId, int $firstProgramId, mixed $drahtId, mixed $contaoId): void
    {
        if ($drahtId === null && $contaoId === null) {
            return;
        }

        $exists = DB::table('event_program')
            ->where('event', $eventId)
            ->where('first_program', $firstProgramId)
            ->exists();

        if ($exists) {
            return;
        }

        $programExists = DB::table('m_first_program')->where('id', $firstProgramId)->exists();
        if (! $programExists) {
            return;
        }

        DB::table('event_program')->insert([
            'event' => $eventId,
            'first_program' => $firstProgramId,
            'draht_id' => $drahtId !== null && $drahtId !== '' ? (int) $drahtId : null,
            'contao_id' => $contaoId !== null && $contaoId !== '' ? (int) $contaoId : null,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('event', 'event_explore')) {
            Schema::table('event', function (Blueprint $table) {
                $table->unsignedSmallInteger('event_explore')->nullable();
                $table->unsignedSmallInteger('event_challenge')->nullable();
                $table->unsignedInteger('contao_id_explore')->nullable();
                $table->unsignedInteger('contao_id_challenge')->nullable();
            });
        }

        if (Schema::hasTable('event_program')) {
            $rows = DB::table('event_program')->get();
            foreach ($rows as $row) {
                $updates = [];
                if ((int) $row->first_program === 2) {
                    $updates['event_explore'] = $row->draht_id;
                    $updates['contao_id_explore'] = $row->contao_id;
                } elseif ((int) $row->first_program === 3) {
                    $updates['event_challenge'] = $row->draht_id;
                    $updates['contao_id_challenge'] = $row->contao_id;
                }
                if ($updates !== []) {
                    DB::table('event')->where('id', $row->event)->update($updates);
                }
            }

            Schema::dropIfExists('event_program');
        }
    }
};
