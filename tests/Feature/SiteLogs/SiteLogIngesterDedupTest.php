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

function dedupLogLine(string $requestId, ?string $ts = null): string
{
    // Default to a timestamp inside the 24h dedup window so dedup actually exercises.
    $ts ??= now()->format('d/M/Y:H:i:s O');

    return sprintf(
        '[%s] Host: example.com | IP: 1.2.3.4 | ReqID: %s | Path: / | Request URI: / | FBCLID: - | UA: "bot" | ISO: "US" | Prefetch: [document] | Turbolink: [-] | client hints: ["Chromium";v="147"] -  ["macOS"] -  [?0]',
        $ts,
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

it('still attaches the gate pivot match on the gate pass even when the request_id is already in DB', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, dedupLogLine('req-a')."\n");

    $server = aDedupServer();

    SiteLogEntry::factory()->for($server)->create([
        'request_id' => 'req-a',
        'site_id' => '3075741',
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::matchedLog('gate')->where('request_id', 'req-a')->exists())->toBeTrue();
});

it('skips already-tagged request ids on the per-slug pass to avoid redundant pivot inserts', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, dedupLogLine('req-a')."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, dedupLogLine('req-a')."\n");

    $server = aDedupServer();

    // First run tags req-a with both access + gate.
    $this->ingester->ingestServer($server);
    $second = $this->ingester->ingestServer($server);

    expect($second->matchCounts)->toBe(['access' => 0, 'gate' => 0])
        ->and(SiteLogEntry::matchedLog('gate')->where('request_id', 'req-a')->count())->toBe(1);
});

it('populates browser / OS / device / is_bot on ingest', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $chromeLine = '[14/Apr/2026:17:44:07 +0000] Host: example.com | IP: 1.2.3.4 | ReqID: req-chrome | Path: / | Request URI: / | FBCLID: - | UA: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" | ISO: "US" | Prefetch: [document] | Turbolink: [-] | client hints: [] -  [] -  []';
    $botLine = '[14/Apr/2026:17:44:08 +0000] Host: example.com | IP: 1.2.3.5 | ReqID: req-bot | Path: / | Request URI: / | FBCLID: - | UA: "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" | ISO: "US" | Prefetch: [] | Turbolink: [] | client hints: [] -  [] -  []';

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $chromeLine."\n".$botLine."\n");

    $this->ingester->ingestServer(aDedupServer());

    $chrome = SiteLogEntry::query()->where('request_id', 'req-chrome')->firstOrFail();
    $bot = SiteLogEntry::query()->where('request_id', 'req-bot')->firstOrFail();

    expect($chrome->browser_name)->toBe('Chrome')
        ->and($chrome->os_name)->toBe('Mac')
        ->and($chrome->device_type)->toBe('desktop')
        ->and($chrome->is_bot)->toBeFalse()
        ->and($bot->is_bot)->toBeTrue()
        ->and($bot->browser_name)->toBeNull();
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
