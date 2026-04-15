<?php

declare(strict_types=1);

use App\Services\SiteLogs\UserAgentParser;

it('parses a chrome desktop user agent', function () {
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    $parsed = (new UserAgentParser)->parse($ua);

    expect($parsed['is_bot'])->toBeFalse()
        ->and($parsed['browser_name'])->toBe('Chrome')
        ->and($parsed['os_name'])->toBe('Mac')
        ->and($parsed['device_type'])->toBe('desktop')
        ->and($parsed['browser_version'])->not->toBeNull()
        ->and($parsed['os_version'])->not->toBeNull();
});

it('flags googlebot as a bot', function () {
    $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    $parsed = (new UserAgentParser)->parse($ua);

    expect($parsed['is_bot'])->toBeTrue()
        ->and($parsed['browser_name'])->toBeNull()
        ->and($parsed['os_name'])->toBeNull()
        ->and($parsed['device_type'])->toBeNull();
});

it('returns all-null for empty user agent', function () {
    $parser = new UserAgentParser;

    expect($parser->parse(null))->toMatchArray([
        'browser_name' => null,
        'browser_version' => null,
        'os_name' => null,
        'os_version' => null,
        'device_type' => null,
        'is_bot' => false,
    ]);

    expect($parser->parse(''))->toMatchArray([
        'is_bot' => false,
        'browser_name' => null,
    ]);
});

it('caches parse results in-memory across calls', function () {
    $parser = new UserAgentParser;
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    $first = $parser->parse($ua);
    $second = $parser->parse($ua);

    expect($second)->toBe($first);
});

it('identifies a mobile device', function () {
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    $parsed = (new UserAgentParser)->parse($ua);

    expect($parsed['device_type'])->toBe('smartphone')
        ->and($parsed['os_name'])->toBe('iOS');
});
