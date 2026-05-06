<?php

declare(strict_types=1);

use App\Jobs\IngestSiteLogsJob;
use App\Models\Server;
use App\Services\Dashboard\SiteTrafficAggregator;
use App\Services\Nginx\DomainCache;
use App\Services\SiteLogs\SiteLogIngester;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Bus::fake();
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

it('busts the dashboard snapshot cache after an ingest that inserted rows', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    Cache::put(SiteTrafficAggregator::SNAPSHOT_CACHE_KEY, 'stale-payload', 60);

    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $line = '[14/Apr/2026:17:44:07 +0000] Host: example.com | IP: 1.2.3.4 | ReqID: req-cache-bust | Path: / | Request URI: / | FBCLID: - | UA: "bot" | ISO: "US" | Prefetch: [document] | Turbolink: [-] | client hints: ["Chromium";v="147"] -  ["macOS"] -  [?0]';
    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $line."\n");

    (new IngestSiteLogsJob)->handle(app(SiteLogIngester::class), app(DomainCache::class));

    expect(Cache::has(SiteTrafficAggregator::SNAPSHOT_CACHE_KEY))->toBeFalse();
});

it('leaves the dashboard snapshot cache intact when an ingest had no inserts or updates', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    Cache::put(SiteTrafficAggregator::SNAPSHOT_CACHE_KEY, 'still-warm', 60);

    $this->fake->shouldReturn(0, "\n");

    (new IngestSiteLogsJob)->handle(app(SiteLogIngester::class), app(DomainCache::class));

    expect(Cache::get(SiteTrafficAggregator::SNAPSHOT_CACHE_KEY))->toBe('still-warm');
});
