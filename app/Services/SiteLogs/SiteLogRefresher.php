<?php

declare(strict_types=1);

namespace App\Services\SiteLogs;

use App\Jobs\IngestSiteLogsJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class SiteLogRefresher
{
    public const LOCK_KEY = 'site-logs.ingest.lock';

    public const LAST_RUN_KEY = 'site-logs.ingest.last_run';

    public const LAST_REPORT_KEY = 'site-logs.ingest.last_report';

    private const LOCK_TTL_SECONDS = 180;

    private const DEFAULT_MAX_AGE_SECONDS = 90;

    /**
     * Returns one of:
     *   'fresh'     — last run is within the max age; nothing dispatched.
     *   'running'   — another request is already ingesting; nothing dispatched.
     *   'triggered' — we dispatched an after-response ingest (had prior data).
     *   'never'     — we dispatched the first-ever ingest (no prior data).
     */
    public function ensureFresh(int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS): string
    {
        $age = $this->staleness();

        if ($age !== null && $age <= $maxAgeSeconds) {
            return 'fresh';
        }

        if (! Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS)->get()) {
            return 'running';
        }

        IngestSiteLogsJob::dispatchAfterResponse();

        return $age === null ? 'never' : 'triggered';
    }

    /**
     * Run the ingest synchronously, right here, right now. Use this when
     * the user explicitly asks for a refresh (e.g. the "Sync now"
     * dashboard button) so they get instant feedback and visible errors.
     * Returns the report so callers can render a notification.
     *
     * @return array{inserted:int,updated:int,skipped:int,errors:list<string>,duration_ms:int,ran_at:int}
     */
    public function syncNow(): array
    {
        // Steal the lock so any in-flight after-response dispatch yields.
        Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS)->forceRelease();
        Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS)->get();

        Bus::dispatchSync(new IngestSiteLogsJob);

        return $this->lastReport() ?? [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'duration_ms' => 0,
            'ran_at' => now()->timestamp,
        ];
    }

    public function staleness(): ?int
    {
        $last = Cache::get(self::LAST_RUN_KEY);

        if ($last === null) {
            return null;
        }

        return max(0, now()->timestamp - (int) $last);
    }

    /**
     * @return array{inserted:int,updated:int,skipped:int,errors:list<string>,duration_ms:int,ran_at:int}|null
     */
    public function lastReport(): ?array
    {
        $report = Cache::get(self::LAST_REPORT_KEY);

        return is_array($report) ? $report : null;
    }
}
