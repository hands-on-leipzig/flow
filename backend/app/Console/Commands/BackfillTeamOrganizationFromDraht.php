<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\DrahtTeamEnrichmentService;
use Illuminate\Console\Command;

class BackfillTeamOrganizationFromDraht extends Command
{
    protected $signature = 'draht:backfill-team-details
                            {--event= : FLOW event id}
                            {--season= : Limit to events in this season id}';

    protected $description = 'Backfill team organization and location from DRAHT scheduledata';

    public function handle(DrahtTeamEnrichmentService $enrichment): int
    {
        $eventId = $this->option('event');
        $seasonId = $this->option('season');

        $query = Event::query()->orderBy('id');
        if ($eventId) {
            $query->where('id', (int) $eventId);
        } elseif ($seasonId) {
            $query->where('season', (int) $seasonId);
        }

        $events = $query->get();
        if ($events->isEmpty()) {
            $this->warn('No events found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$events->count()} event(s)...");
        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        $totalUpdated = 0;
        foreach ($events as $event) {
            try {
                $totalUpdated += $enrichment->enrichEvent($event);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Event {$event->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Updated {$totalUpdated} team row(s).");

        return self::SUCCESS;
    }
}
