<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Models\SiteLogEntryLogMatch;
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

it('ingests access.log lines into site_log_entries and tags them with the access pivot match', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $content = logLine('req-a')."\n".logLine('req-b')."\n";

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, $content);

    $server = aLogServer();
    $report = $this->ingester->ingestServer($server);

    expect($report->rowsInserted)->toBe(2)
        ->and($report->linesSkipped)->toBe(0)
        ->and($report->matchCounts)->toMatchArray(['access' => 2]);

    expect(SiteLogEntry::query()->pluck('request_id')->all())
        ->toEqualCanonicalizing(['req-a', 'req-b']);

    $reqA = SiteLogEntry::query()->where('request_id', 'req-a')->firstOrFail();

    expect(SiteLogEntry::query()->value('site_id'))->toBe('3075741')
        ->and($reqA->logMatches()->pluck('log_slug')->all())->toBe(['access'])
        ->and(SiteLogEntry::matchedLog('gate')->exists())->toBeFalse();
});

it('attaches a gate pivot match for request ids that also appear in gate.log', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, logLine('req-a')."\n".logLine('req-b')."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, logLine('req-b')."\n");

    $server = aLogServer();
    $report = $this->ingester->ingestServer($server);

    $reqA = SiteLogEntry::query()->where('request_id', 'req-a')->firstOrFail();
    $reqB = SiteLogEntry::query()->where('request_id', 'req-b')->firstOrFail();

    expect($reqA->logMatches()->pluck('log_slug')->all())->toEqualCanonicalizing(['access'])
        ->and($reqB->logMatches()->pluck('log_slug')->all())->toEqualCanonicalizing(['access', 'gate'])
        ->and($report->matchCounts)->toMatchArray(['access' => 2, 'gate' => 1]);
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

    expect(SiteLogEntry::query()->count())->toBe(2)
        ->and(SiteLogEntryLogMatch::query()->where('log_slug', 'access')->count())->toBe(2);
});

it('a second ingest with the row now appearing in gate.log attaches the gate pivot without inserting dupes', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $gatePath = '/var/log/nginx/site-3075741-gate.log';

    $this->fake->shouldReturn(0, $accessPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, logLine('req-a')."\n");

    $server = aLogServer();
    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::matchedLog('gate')->exists())->toBeFalse();

    // second ingest: gate.log now exists with the same request id
    $this->fake->shouldReturn(0, $accessPath."\n".$gatePath."\n");
    $this->fake->shouldReturnForCommand($gatePath, 0, logLine('req-a')."\n");

    $this->ingester->ingestServer($server);

    expect(SiteLogEntry::query()->count())->toBe(1)
        ->and(SiteLogEntry::matchedLog('gate')->where('request_id', 'req-a')->exists())->toBeTrue();
});

it('ingests custom-slug log files as tag-only passes (no new rows)', function () {
    $accessPath = '/var/log/nginx/site-3075741-access.log';
    $fbPath = '/var/log/nginx/site-3075741-fb_only.log';

    $this->fake->shouldReturn(0, $accessPath."\n".$fbPath."\n");
    $this->fake->shouldReturnForCommand($accessPath, 0, logLine('req-a')."\n".logLine('req-b')."\n");
    $this->fake->shouldReturnForCommand($fbPath, 0, logLine('req-b')."\n");

    $server = aLogServer();
    $report = $this->ingester->ingestServer($server);

    expect($report->matchCounts)->toMatchArray(['access' => 2, 'fb_only' => 1])
        ->and(SiteLogEntry::query()->count())->toBe(2)
        ->and(SiteLogEntry::matchedLog('fb_only')->pluck('request_id')->all())->toBe(['req-b']);
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
