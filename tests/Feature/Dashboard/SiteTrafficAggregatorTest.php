<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Services\Dashboard\SiteTrafficAggregator;
use App\Services\Nginx\DomainCache;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

it('aggregates today totals and per-site rows from site_log_entries', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    // today's rows
    SiteLogEntry::factory()->for($server)->count(5)->today()->create(['site_id' => '100']);
    SiteLogEntry::factory()->for($server)->count(2)->today()->gated()->create(['site_id' => '100']);
    SiteLogEntry::factory()->for($server)->count(3)->today()->withFbclid()->create(['site_id' => '100']);
    SiteLogEntry::factory()->for($server)->count(4)->today()->create(['site_id' => '200']);

    // yesterday's rows should NOT count
    SiteLogEntry::factory()->for($server)->count(10)->create([
        'site_id' => '100',
        'occurred_at' => now()->subDay(),
    ]);

    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    $snapshot = app(SiteTrafficAggregator::class)->snapshot();

    expect($snapshot->totalVisits)->toBe(14)
        ->and($snapshot->totalGateHits)->toBe(2)
        ->and($snapshot->totalFbclidHits)->toBe(3)
        ->and($snapshot->activeInjectionSites)->toBe(2)
        ->and($snapshot->rows)->toHaveCount(2);

    $top = $snapshot->rows[0];
    expect($top->siteId)->toBe('100')
        ->and($top->visits)->toBe(10)
        ->and($top->gateHits)->toBe(2)
        ->and($top->fbclidHits)->toBe(3);
});

it('enriches rows with forge domains persisted in server_sites', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known', 'name' => 'edge-01']);
    SiteLogEntry::factory()->for($server)->count(3)->today()->create(['site_id' => '3075741']);

    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/test.bestpropfirmsuk.com\n/etc/nginx/forge-conf/3075741/loveable-projects-x.on-forge.com\n",
    );

    // Populate the persisted catalog the same way the ingest job does.
    app(DomainCache::class)->sync($server);

    $snapshot = app(SiteTrafficAggregator::class)->snapshot();

    expect($snapshot->rows[0]->firstDomain)->toBe('loveable-projects-x.on-forge.com')
        ->and($snapshot->rows[0]->domains)->toContain('test.bestpropfirmsuk.com');
});

it('caches the DTO across snapshot() calls and refreshes after forgetSnapshot()', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    SiteLogEntry::factory()->for($server)->count(2)->today()->create(['site_id' => '100']);
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    $aggregator = app(SiteTrafficAggregator::class);

    expect($aggregator->snapshot()->totalVisits)->toBe(2);

    // Within TTL: new rows are not visible until the snapshot is invalidated.
    SiteLogEntry::factory()->for($server)->count(100)->today()->create(['site_id' => '100']);
    expect($aggregator->snapshot()->totalVisits)->toBe(2);

    $aggregator->forgetSnapshot();
    expect($aggregator->snapshot()->totalVisits)->toBe(102);
});

it('reads domains entirely from the DB on dashboard render — zero SSH per snapshot()', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    SiteLogEntry::factory()->for($server)->count(1)->today()->create(['site_id' => '3075741']);
    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/example.com\n",
    );

    // sync() is the only path that opens an SSH session.
    app(DomainCache::class)->sync($server);
    $callsAfterSync = collect($this->fake->commands)
        ->filter(fn (string $c): bool => str_contains($c, '-type d -not -name server'))
        ->count();

    $aggregator = app(SiteTrafficAggregator::class);
    $aggregator->snapshot();
    $aggregator->forgetSnapshot();
    $aggregator->snapshot();
    $aggregator->forgetSnapshot();
    $aggregator->snapshot();

    $callsAfterRenders = collect($this->fake->commands)
        ->filter(fn (string $c): bool => str_contains($c, '-type d -not -name server'))
        ->count();

    expect($callsAfterSync)->toBe(1)
        ->and($callsAfterRenders)->toBe(1);
});

it('falls back to no domain when the SSH enumeration fails on a server', function () {
    $server = Server::factory()->create([
        'host_fingerprint' => 'mismatch',
    ]);
    SiteLogEntry::factory()->for($server)->count(1)->today()->create(['site_id' => '3075741']);

    $snapshot = app(SiteTrafficAggregator::class)->snapshot();

    expect($snapshot->rows[0]->firstDomain)->toBeNull()
        ->and($snapshot->rows[0]->domains)->toBe([]);
});
