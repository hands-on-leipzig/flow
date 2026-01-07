<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\EventAttentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateEventAttention extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:calculate-attention {--season= : Calculate for specific season ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and update needs_attention status for all events (or specific season)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $seasonId = $this->option('season');
        
        $query = Event::query();
        if ($seasonId) {
            $query->where('season', $seasonId);
            $this->info("Calculating attention status for season {$seasonId}...");
        } else {
            $this->info("Calculating attention status for all events...");
        }
        
        $events = $query->get();
        $total = $events->count();
        
        if ($total === 0) {
            $this->warn("No events found.");
            return 0;
        }
        
        $this->info("Found {$total} events to process.");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $attentionService = app(EventAttentionService::class);
        $needsAttention = 0;
        
        foreach ($events as $event) {
            try {
                $attentionService->updateEventAttentionStatus($event->id);
                if ($event->fresh()->needs_attention) {
                    $needsAttention++;
                }
                $bar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to calculate attention for event {$event->id}: " . $e->getMessage());
            }
        }
        
        $bar->finish();
        $this->newLine();
        $this->info("Completed! {$needsAttention} of {$total} events need attention.");
        
        return 0;
    }
}
