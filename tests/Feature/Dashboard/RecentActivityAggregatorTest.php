<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Dashboard\RecentActivityAggregator;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

it('returns the most recent managed edits across servers, sorted desc', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known', 'name' => 'edge-01']);

    $findOutput = implode("\n", [
        '1760451000 /etc/nginx/conf.d/redteam-forge-3075741.conf',
        '1760450400 /etc/nginx/forge-conf/3075741/server/redteam-analytics.conf',
        '1760449800 /etc/nginx/conf.d/redteam-forge-3075742.conf',
    ])."\n";

    $this->fake->shouldReturnForCommand('-printf', 0, $findOutput);
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    $edits = app(RecentActivityAggregator::class)->latest(10);

    expect($edits)->toHaveCount(3)
        ->and($edits[0]->siteId)->toBe('3075741')
        ->and($edits[0]->fileKind)->toBe('http')
        ->and($edits[1]->siteId)->toBe('3075741')
        ->and($edits[1]->fileKind)->toBe('server')
        ->and($edits[2]->siteId)->toBe('3075742')
        ->and($edits[0]->editedAt->getTimestamp())->toBeGreaterThan($edits[2]->editedAt->getTimestamp());
});

it('tags edits with the first known forge domain for the site', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $this->fake->shouldReturnForCommand(
        '-printf',
        0,
        "1760451000 /etc/nginx/forge-conf/3075741/server/redteam-analytics.conf\n",
    );
    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/test.bestpropfirmsuk.com\n",
    );

    $edits = app(RecentActivityAggregator::class)->latest();

    expect($edits[0]->firstDomain)->toBe('test.bestpropfirmsuk.com');
});

it('limits the result set to the requested count', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $lines = [];
    for ($i = 0; $i < 20; $i++) {
        $lines[] = (1760451000 - $i)." /etc/nginx/forge-conf/30757{$i}/server/redteam-analytics.conf";
    }

    $this->fake->shouldReturnForCommand('-printf', 0, implode("\n", $lines)."\n");
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    $edits = app(RecentActivityAggregator::class)->latest(5);

    expect($edits)->toHaveCount(5);
});

it('caches only raw scan strings, not typed DTOs (so DTO shape changes do not poison the cache)', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $this->fake->shouldReturnForCommand(
        '-printf',
        0,
        "1760451000 /etc/nginx/conf.d/redteam-forge-3075741.conf\n",
    );
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    app(RecentActivityAggregator::class)->latest();

    $cached = Cache::get("dashboard.recent-activity.scan.server-{$server->id}");

    expect($cached)->toBeArray()
        ->and($cached[0] ?? null)->toBeString()
        ->and($cached[0])->toContain('redteam-forge-3075741.conf');
});

it('skips unreachable servers rather than failing the whole widget', function () {
    Server::factory()->create(['host_fingerprint' => 'mismatch', 'name' => 'bad']);
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known', 'name' => 'good']);

    $this->fake->shouldReturnForCommand(
        '-printf',
        0,
        "1760451000 /etc/nginx/forge-conf/3075741/server/redteam-analytics.conf\n",
    );
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    $edits = app(RecentActivityAggregator::class)->latest();

    expect($edits)->toHaveCount(1)
        ->and($edits[0]->serverName)->toBe('good');
});
