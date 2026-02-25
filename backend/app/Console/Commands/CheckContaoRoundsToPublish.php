<?php

namespace App\Console\Commands;

use App\Services\ContaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface as Logger;
use Carbon\Carbon;

class CheckContaoRoundsToPublish extends Command
{
    protected $signature = 'contao:publish-rounds';
    protected $description = 'Check Contao for rounds to publish based on event date and update Contao accordingly';

    public function __construct(private readonly ContaoService $contaoService, private readonly Logger $log)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->log?->info('CheckContaoRoundsToPublish started.');

        try {
            $today = Carbon::today()->format('Y-m-d');

            // TODO: Wie umgehen mit multi-day events (Finale)?
            // Finde Veranstaltungen, die heute stattfinden, um Contao-Daten zu aktualisieren.
            $eventIds = DB::table('event')
                ->whereNotNull('contao_id_challenge')
                ->whereRaw('? BETWEEN date AND DATE_ADD(date, INTERVAL (days - 1) DAY)', [$today])
                ->pluck('id')
                ->unique()
                ->filter()
                ->all();

            $this->log?->info('Events found for today', ['count' => count($eventIds)]);

            foreach ($eventIds as $eventId) {
                try {
                    $tournamentId = $this->contaoService->getTournamentId($eventId);
                    $previous = $this->contaoService->readRoundsToShow($eventId);
                    $result = $this->contaoService->updateRoundsToShow($eventId, $tournamentId);

                    // Teamnamen für nächste Runde aktualisieren
                    $this->contaoService->updateAllMatchups($previous, $result, $tournamentId, $eventId);
                } catch (\Throwable $e) {
                    $this->log?->error('Error calling updateRoundsToShow for event', ['event' => $eventId, 'message' => $e->getMessage()]);
                }
            }

            $this->log?->info('CheckContaoRoundsToPublish finished.');

            return count($eventIds);
        } catch (\Throwable $e) {
            $this->log?->error('CheckContaoRoundsToPublish failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }
}

