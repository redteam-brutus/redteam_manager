<?php

declare(strict_types=1);

use App\Models\SiteLogEntry;

it('populates browser / OS / device / is_bot on rows with null parsed fields', function () {
    $chromeUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $botUa = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    $chrome = SiteLogEntry::factory()->create([
        'user_agent' => $chromeUa,
        'browser_name' => null,
        'os_name' => null,
        'device_type' => null,
        'is_bot' => false,
    ]);
    $bot = SiteLogEntry::factory()->create([
        'user_agent' => $botUa,
        'browser_name' => null,
        'os_name' => null,
        'device_type' => null,
        'is_bot' => false,
    ]);

    $this->artisan('site-logs:backfill-user-agents')->assertSuccessful();

    $chrome->refresh();
    $bot->refresh();

    expect($chrome->browser_name)->toBe('Chrome')
        ->and($chrome->os_name)->toBe('Mac')
        ->and($chrome->device_type)->toBe('desktop')
        ->and($chrome->is_bot)->toBeFalse()
        ->and($bot->is_bot)->toBeTrue()
        ->and($bot->browser_name)->toBeNull();
});

it('skips rows that are already backfilled', function () {
    $row = SiteLogEntry::factory()->create([
        'user_agent' => 'ua-x',
        'browser_name' => 'Already',
    ]);

    $this->artisan('site-logs:backfill-user-agents')->assertSuccessful();

    $row->refresh();

    expect($row->browser_name)->toBe('Already');
});

it('rejects a chunk size below 1', function () {
    $this->artisan('site-logs:backfill-user-agents', ['--chunk' => '0'])->assertFailed();
});
