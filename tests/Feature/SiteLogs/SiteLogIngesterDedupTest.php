<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Services\SiteLogs\SiteLogIngester;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
    $this->ingester = app(SiteLogIngester::class);
});

function dedupLogLine(string $requestId): string
{
    return sprintf(
        '[14/Apr/2026:17:44:07 +0000] Host: example.com | IP: 1.2.3.4 | ReqID: %s | Path: / | Request URI: / | FBCLID: - | UA: "bot" | ISO: "US" | Prefetch: [document] | Turbolink: [-] | client hints: ["Chromium";v="147"] -  ["macOS"] -  [?0]',
        $requestId,
    );
}

function aDedupServer(): Server
{
    return Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
}

it('skips access lines whose request_id is already in the last-24h window', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n".dedupLogLine('req-b')."\n");

    $server = aDedupServer();

    SiteLogEntry::factory()->for($server)->create([
        'request_id' => 'req-a',
        'site_id' => '3075741',
        'occurred_at' => now()->subMinutes(10),
    ]);

    $report = $this->ingester->ingestServer($server);

    expect($report->rowsInserted)->toBe(1)
        ->and(SiteLogEntry::query()->count())->toBe(2);
});

it('re-ingesting the same file is a no-op after the first run', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n".dedupLogLine('req-b')."\n");

    $server = aDedupServer();

    $this->ingester->ingestServer($server);
    $second = $this->ingester->ingestServer($server);

    expect($second->rowsInserted)->toBe(0)
        ->and(SiteLogEntry::query()->count())->toBe(2);
});

it('still promotes gated=true on the gate pass even when the request_id is already in DB', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, dedupLogLine('req-a')."\n");

    $server = aDedupServer();

    SiteLogEntry::factory()->for($server)->create([
        'request_id' => 'req-a',
        'site_id' => '3075741',
        'gated' => false,
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->where('request_id', 'req-a')->value('gated'))->toBeTrue();
});

it('does not skip lines older than the 24h dedup window', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n");

    $server = aDedupServer();

    SiteLogEntry::factory()->for($server)->create([
        'request_id' => 'req-a',
        'site_id' => '3075741',
        'occurred_at' => now()->subHours(30),
    ]);

    $report = $this->ingester->ingestServer($server);

    // line is parsed + upserted (no skip) because the existing row fell outside the 24h window
    expect($report->rowsInserted)->toBe(1)
        ->and(SiteLogEntry::query()->count())->toBe(1);
});
