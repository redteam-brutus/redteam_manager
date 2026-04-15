<?php

declare(strict_types=1);

use App\Services\SiteLogs\VerboseLogLineParser;

function realLogLine(): string
{
    return '[14/Apr/2026:17:44:07 +0000] Host: test.bestpropfirmsuk.com | IP: 2a06:c701:75fb:7b00:b4ca:e463:631e:de27 | ReqID: da00b35787d3f0b0ba121259776cc7cf | Path: /index.html | Request URI: /?hey=hey | FBCLID: - | UA: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36" | ISO: "IL" | Prefetch: [document] | Turbolink: [-] | client hints: ["Google Chrome";v="147", "Chromium";v="147"] -  ["macOS"] -  [?0]';
}

it('parses a real escape=none access line into typed fields', function () {
    $parsed = (new VerboseLogLineParser)->parse(realLogLine());

    expect($parsed)->not->toBeNull()
        ->and($parsed['request_id'])->toBe('da00b35787d3f0b0ba121259776cc7cf')
        ->and($parsed['host'])->toBe('test.bestpropfirmsuk.com')
        ->and($parsed['remote_addr'])->toBe('2a06:c701:75fb:7b00:b4ca:e463:631e:de27')
        ->and($parsed['uri'])->toBe('/index.html')
        ->and($parsed['request_uri'])->toBe('/?hey=hey')
        ->and($parsed['fbclid'])->toBeNull()
        ->and($parsed['user_agent'])->toStartWith('Mozilla/5.0')
        ->and($parsed['iso_country'])->toBe('IL')
        ->and($parsed['prefetch'])->toBe('document')
        ->and($parsed['turbolink'])->toBeNull()
        ->and($parsed['sec_ch_ua'])->toContain('Chromium')
        ->and($parsed['sec_ch_ua_platform'])->toBe('"macOS"')
        ->and($parsed['sec_ch_ua_mobile'])->toBe('?0');
});

it('captures occurred_at as a Carbon-compatible value in UTC', function () {
    $parsed = (new VerboseLogLineParser)->parse(realLogLine());

    expect($parsed['occurred_at']->format('Y-m-d H:i:s'))->toBe('2026-04-14 17:44:07')
        ->and($parsed['occurred_at']->getOffset())->toBe(0);
});

it('returns non-null fbclid for real values', function () {
    $line = str_replace('FBCLID: -', 'FBCLID: IwAR1xyz_abc-DEF', realLogLine());

    expect((new VerboseLogLineParser)->parse($line)['fbclid'])->toBe('IwAR1xyz_abc-DEF');
});

it('returns null for malformed or empty lines rather than throwing', function () {
    $parser = new VerboseLogLineParser;

    expect($parser->parse(''))->toBeNull()
        ->and($parser->parse('not a log line at all'))->toBeNull()
        ->and($parser->parse('[14/Apr/2026:17:44:07] missing fields'))->toBeNull();
});

it('rejects lines with invalid timestamps', function () {
    $line = str_replace('[14/Apr/2026:17:44:07 +0000]', '[not-a-date]', realLogLine());

    expect((new VerboseLogLineParser)->parse($line))->toBeNull();
});

it('preserves the raw line verbatim for re-parse safety', function () {
    $parsed = (new VerboseLogLineParser)->parse(realLogLine());

    expect($parsed['raw_line'])->toBe(realLogLine());
});

it('parses lines where FBCLID is empty (no dash, escape=none format)', function () {
    // Under escape=none, a missing $arg_fbclid renders as an empty field: "FBCLID: |"
    $line = '[15/Apr/2026:00:22:51 +0000] Host: test.bestpropfirmsuk.com | IP: 143.244.47.83 | ReqID: 728217ca3a81ea99d124f7398b35be47 | Path: /index.html | Request URI: / | FBCLID: | UA: "Mozilla/5.0" | ISO: "US" | Prefetch: [] | Turbolink: [] | client hints: [] -  [] -  []';

    $parsed = (new VerboseLogLineParser)->parse($line);

    expect($parsed)->not->toBeNull()
        ->and($parsed['fbclid'])->toBeNull()
        ->and($parsed['request_id'])->toBe('728217ca3a81ea99d124f7398b35be47')
        ->and($parsed['iso_country'])->toBe('US');
});

it('parses lines where Prefetch / Turbolink / client hints arrive as empty brackets', function () {
    $line = '[15/Apr/2026:00:22:51 +0000] Host: test.bestpropfirmsuk.com | IP: 143.244.47.83 | ReqID: 72fc3eb7ac20984b94b63f3ea89a21a7f75004e1 | Path: /index.html | Request URI: / | FBCLID: - | UA: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.3029.110 Safari/537.3" | ISO: "US" | Prefetch: [] | Turbolink: [] | client hints: [] -  [] -  []';

    $parsed = (new VerboseLogLineParser)->parse($line);

    expect($parsed)->not->toBeNull()
        ->and($parsed['prefetch'])->toBeNull()
        ->and($parsed['turbolink'])->toBeNull()
        ->and($parsed['sec_ch_ua'])->toBeNull()
        ->and($parsed['sec_ch_ua_platform'])->toBeNull()
        ->and($parsed['sec_ch_ua_mobile'])->toBeNull()
        ->and($parsed['iso_country'])->toBe('US');
});
