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

function aLogServer(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);
}

function logLine(string $requestId, string $fbclid = '-', string $ts = '14/Apr/2026:17:44:07 +0000'): string
{
    return sprintf(
        '[%s] Host: example.com | IP: 1.2.3.4 | ReqID: %s | Path: / | Request URI: / | FBCLID: %s | UA: "bot" | ISO: "US" | Prefetch: [document] | Turbolink: [-] | client hints: ["Chromium";v="147"] -  ["macOS"] -  [?0]',
        $ts,
        $requestId,
        $fbclid,
    );
}

it('ingests access.log lines into site_log_entries with gated=false', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $content = logLine('req-a')."\n".logLine('req-b')."\n";

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $content);

    $server = aLogServer();
    $report = $this->ingester->ingestServer($server);

    expect($report->rowsInserted)->toBe(2)
        ->and($report->linesSkipped)->toBe(0);

    expect(SiteLogEntry::query()->pluck('request_id')->all())
        ->toEqualCanonicalizing(['req-a', 'req-b']);

    expect(SiteLogEntry::query()->where('request_id', 'req-a')->first())
        ->gated->toBeFalse()
        ->and(SiteLogEntry::query()->value('site_id'))->toBe('3075741');
});

it('promotes gated=true for request ids that also appear in gate.log', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, logLine('req-a')."\n".logLine('req-b')."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, logLine('req-b')."\n");

    $server = aLogServer();
    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->where('request_id', 'req-a')->value('gated'))->toBeFalse()
        ->and(SiteLogEntry::query()->where('request_id', 'req-b')->value('gated'))->toBeTrue();
});

it('is idempotent across successive ingests (request_id is the dedup key)', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $content = logLine('req-a')."\n".logLine('req-b')."\n";

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $content);

    $server = aLogServer();

    $this->ingester->ingestServer($server);
    $this->ingester->ingestServer($server);
    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->count())->toBe(2);
});

it('a second ingest with the row now appearing in gate.log promotes gated without inserting dupes', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, logLine('req-a')."\n");

    $server = aLogServer();
    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->where('request_id', 'req-a')->value('gated'))->toBeFalse();

    // second ingest: gate.log now exists with the same request id
    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, logLine('req-a')."\n");

    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->count())->toBe(1)
        ->and(SiteLogEntry::query()->where('request_id', 'req-a')->value('gated'))->toBeTrue();
});

it('counts unparseable lines as skipped without failing the batch', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $content = logLine('req-a')."\nthis is not a log line\n".logLine('req-b')."\n";

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $content);

    $report = $this->ingester->ingestServer(aLogServer());

    expect($report->rowsInserted)->toBe(2)
        ->and($report->linesSkipped)->toBe(1);
});

it('captures per-site SSH failure as an error without aborting the whole run', function () {
    $this->fake->shouldReturn(0, "/var/log/nginx/site-3075741-access.log\n");
    $this->fake->shouldReturnForCommand('tail', 1, '');

    $report = $this->ingester->ingestServer(aLogServer());

    expect($report->rowsInserted)->toBe(0)
        ->and($report->errors)->toBe([]);
});
