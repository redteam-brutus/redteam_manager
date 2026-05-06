<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Dashboard\SiteTrafficAggregator;
use App\Services\Nginx\DomainCache;
use App\Services\SiteLogs\SiteLogIngester;
use App\Services\SiteLogs\SiteLogRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * On-demand ingest triggered by the dashboard when data is stale.
 * Dispatched via `dispatchAfterResponse()` so the HTTP response is not
 * delayed. Not queued — runs inline in the same PHP process after the
 * response is sent, which keeps dev environments working without a
 * queue worker.
 */
class IngestSiteLogsJob
{
    use Dispatchable;
    use Queueable;

    public function handle(SiteLogIngester $ingester, DomainCache $domains): void
    {
        $start = microtime(true);
        $totals = [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'skipped_samples' => [],
        ];

        try {
            foreach (Server::query()->get() as $server) {
                try {
                    $report = $ingester->ingestServer($server);
                    $totals['inserted'] += $report->rowsInserted;
                    $totals['updated'] += $report->rowsUpdated;
                    $totals['skipped'] += $report->linesSkipped;

                    foreach ($report->errors as $err) {
                        $totals['errors'][] = "{$server->name}: {$err}";
                    }

                    foreach ($report->skippedSamples as $sample) {
                        if (count($totals['skipped_samples']) >= 5) {
                            break;
                        }
                        $totals['skipped_samples'][] = "{$server->name}: {$sample}";
                    }

                    // Refresh persisted server_sites so dashboard widgets and filter dropdowns
                    // can resolve domains without opening their own SSH sessions on render.
                    $domains->sync($server);
                } catch (Throwable $e) {
                    $totals['errors'][] = "{$server->name}: {$e->getMessage()}";
                }
            }

            $totals['duration_ms'] = (int) ((microtime(true) - $start) * 1000);
            $totals['ran_at'] = now()->timestamp;

            Cache::put(SiteLogRefresher::LAST_REPORT_KEY, $totals, 86400);
            Cache::put(SiteLogRefresher::LAST_RUN_KEY, $totals['ran_at'], 86400);

            if ($totals['inserted'] > 0 || $totals['updated'] > 0) {
                Cache::forget(SiteTrafficAggregator::SNAPSHOT_CACHE_KEY);
            }
        } finally {
            Cache::lock(SiteLogRefresher::LOCK_KEY)->forceRelease();
        }
    }
}
