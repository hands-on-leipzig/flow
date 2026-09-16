<?php

namespace App\Console\Commands;

use App\Services\DrahtLinkSyncService;
use App\Services\EventSlugService;
use Illuminate\Console\Command;

/**
 * Push the public links of a season to DRAHT.
 *
 * The web app only pushes an event when someone opens its publication page, so events
 * nobody looked at stay without a link in DRAHT — and pages built from DRAHT data, JOIN
 * above all, then cannot offer one. This command closes that gap and is safe to run
 * repeatedly, for instance nightly.
 *
 * Stored link and QR code are not touched: those are rebuilt by the app itself as soon
 * as they no longer match the registry.
 */
class PushPublicLinksToDraht extends Command
{
    protected $signature = 'flow:push-public-links
        {--season= : Season id, defaults to the current season}
        {--dry-run : Only report what would be pushed}';

    protected $description = 'Push the public event links of a season to DRAHT';

    public function handle(DrahtLinkSyncService $sync, EventSlugService $slugs): int
    {
        $seasonId = (int) ($this->option('season') ?: $slugs->currentSeasonId() ?: 0);
        if ($seasonId <= 0) {
            $this->error('No season given and no current season found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $sync->writesToDraht()) {
            $this->warn('Environment '.app()->environment().' does not write to DRAHT; reporting only.');
        }

        $results = $sync->pushSeason($seasonId, $dryRun);
        if ($results === []) {
            $this->warn("No events found in season {$seasonId}.");

            return self::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($results as $result) {
            $name = $result['name'] !== '' ? $result['name'] : 'event '.$result['event_id'];

            if ($result['pushed'] !== []) {
                $pushed++;
                $this->line(sprintf('  %s → %s (DRAHT %s)', $name, $result['url'], implode(', ', $result['pushed'])));
            }

            if ($result['failed'] !== []) {
                $failed++;
                $this->error(sprintf('  %s failed for DRAHT %s', $name, implode(', ', $result['failed'])));
            }

            if ($result['pushed'] === [] && $result['failed'] === []) {
                $skipped++;
                $this->line(sprintf('  <comment>%s skipped (%s)</comment>', $name, $result['skipped'] ?? 'unknown'));
            }
        }

        $this->info(sprintf(
            'Season %d: %d pushed, %d failed, %d skipped, %d events total.',
            $seasonId,
            $pushed,
            $failed,
            $skipped,
            count($results)
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
