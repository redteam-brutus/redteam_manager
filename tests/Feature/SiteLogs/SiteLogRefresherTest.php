<?php

declare(strict_types=1);

use App\Jobs\IngestSiteLogsJob;
use App\Services\SiteLogs\SiteLogRefresher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Bus::fake();
});

it('reports fresh when last run is within the max age window', function () {
    Cache::put(SiteLogRefresher::LAST_RUN_KEY, now()->timestamp - 30, 3600);

    $state = app(SiteLogRefresher::class)->ensureFresh();

    expect($state)->toBe('fresh');
    Bus::assertNothingDispatched();
});

it('triggers an after-response ingest when data is stale', function () {
    Cache::put(SiteLogRefresher::LAST_RUN_KEY, now()->timestamp - 600, 3600);

    $state = app(SiteLogRefresher::class)->ensureFresh();

    expect($state)->toBe('triggered');
    Bus::assertDispatchedAfterResponse(IngestSiteLogsJob::class);
});

it('returns "never" and dispatches when there is no prior run', function () {
    $state = app(SiteLogRefresher::class)->ensureFresh();

    expect($state)->toBe('never');
    Bus::assertDispatchedAfterResponse(IngestSiteLogsJob::class);
});

it('returns "running" and does not dispatch when the lock is already held', function () {
    Cache::lock(SiteLogRefresher::LOCK_KEY, 180)->get();

    $state = app(SiteLogRefresher::class)->ensureFresh();

    expect($state)->toBe('running');
    Bus::assertNothingDispatched();
});

it('reports null staleness when no ingest has ever completed', function () {
    expect(app(SiteLogRefresher::class)->staleness())->toBeNull();
});

it('reports staleness in seconds since the last successful ingest', function () {
    Cache::put(SiteLogRefresher::LAST_RUN_KEY, now()->timestamp - 45, 3600);

    expect(app(SiteLogRefresher::class)->staleness())->toBe(45);
});

it('returns null for lastReport when no ingest has recorded one', function () {
    expect(app(SiteLogRefresher::class)->lastReport())->toBeNull();
});

it('returns the cached report from lastReport after an ingest records one', function () {
    Cache::put(SiteLogRefresher::LAST_REPORT_KEY, [
        'inserted' => 5,
        'updated' => 2,
        'skipped' => 1,
        'errors' => ['server-a: SSH timeout'],
        'duration_ms' => 1500,
        'ran_at' => now()->timestamp,
    ], 3600);

    $report = app(SiteLogRefresher::class)->lastReport();

    expect($report)->not->toBeNull()
        ->and($report['inserted'])->toBe(5)
        ->and($report['errors'])->toContain('server-a: SSH timeout');
});

it('dispatches an ingest synchronously on syncNow()', function () {
    app(SiteLogRefresher::class)->syncNow();

    Bus::assertDispatchedSync(IngestSiteLogsJob::class);
});
