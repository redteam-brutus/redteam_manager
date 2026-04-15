<?php

declare(strict_types=1);

use App\Filament\Resources\SiteLogs\Pages\ListSiteLogEntries;
use App\Filament\Resources\SiteLogs\Tables\SiteLogEntriesTable;
use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Models\User;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->actingAs(User::factory()->create());
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

it('renders the list page with seeded log rows', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    $rows = SiteLogEntry::factory()->for($server)->count(3)->today()->create(['site_id' => '3075741']);

    Livewire::test(ListSiteLogEntries::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($rows);
});

it('narrows rows when filtering by server_id', function () {
    $serverA = Server::factory()->create();
    $serverB = Server::factory()->create();
    $onA = SiteLogEntry::factory()->for($serverA)->count(2)->today()->create();
    $onB = SiteLogEntry::factory()->for($serverB)->count(3)->today()->create();

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('server_id', $serverA->id)
        ->assertCanSeeTableRecords($onA)
        ->assertCanNotSeeTableRecords($onB);
});

it('narrows rows when filtering by site_id', function () {
    $server = Server::factory()->create();
    $a = SiteLogEntry::factory()->for($server)->count(2)->today()->create(['site_id' => '1111']);
    $b = SiteLogEntry::factory()->for($server)->count(3)->today()->create(['site_id' => '2222']);

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('site_id', '1111')
        ->assertCanSeeTableRecords($a)
        ->assertCanNotSeeTableRecords($b);
});

it('narrows rows when filtering by gated=true', function () {
    $server = Server::factory()->create();
    $gated = SiteLogEntry::factory()->for($server)->count(2)->today()->gated()->create();
    $plain = SiteLogEntry::factory()->for($server)->count(3)->today()->create();

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('gated', true)
        ->assertCanSeeTableRecords($gated)
        ->assertCanNotSeeTableRecords($plain);
});

it('narrows rows when filtering by fbclid presence', function () {
    $server = Server::factory()->create();
    $withFb = SiteLogEntry::factory()->for($server)->count(2)->today()->withFbclid()->create();
    $withoutFb = SiteLogEntry::factory()->for($server)->count(3)->today()->create(['fbclid' => null]);

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('fbclid', true)
        ->assertCanSeeTableRecords($withFb)
        ->assertCanNotSeeTableRecords($withoutFb);
});

it('narrows rows when filtering by iso_country', function () {
    $server = Server::factory()->create();
    $us = SiteLogEntry::factory()->for($server)->count(2)->today()->create(['iso_country' => 'US']);
    $il = SiteLogEntry::factory()->for($server)->count(3)->today()->create(['iso_country' => 'IL']);

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('iso_country', 'US')
        ->assertCanSeeTableRecords($us)
        ->assertCanNotSeeTableRecords($il);
});

it('narrows rows when filtering by host substring', function () {
    $server = Server::factory()->create();
    $match = SiteLogEntry::factory()->for($server)->count(2)->today()->create(['host' => 'test.bestpropfirmsuk.com']);
    $miss = SiteLogEntry::factory()->for($server)->count(3)->today()->create(['host' => 'something-else.com']);

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('host', ['host' => 'bestpropfirmsuk'])
        ->assertCanSeeTableRecords($match)
        ->assertCanNotSeeTableRecords($miss);
});

it('narrows rows when filtering by occurred_at date range', function () {
    $server = Server::factory()->create();
    $recent = SiteLogEntry::factory()->for($server)->count(2)->create(['occurred_at' => now()]);
    $old = SiteLogEntry::factory()->for($server)->count(3)->create(['occurred_at' => now()->subDays(10)]);

    Livewire::test(ListSiteLogEntries::class)
        ->filterTable('occurred_at', [
            'from' => now()->subDays(2)->toDateString(),
            'until' => now()->addDay()->toDateString(),
        ])
        ->assertCanSeeTableRecords($recent)
        ->assertCanNotSeeTableRecords($old);
});

it('labels site filter options with the site\'s domains so operators can search by domain', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    SiteLogEntry::factory()->for($server)->today()->create(['site_id' => '3075741']);

    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/test.bestpropfirmsuk.com\n".
        "/etc/nginx/forge-conf/3075741/loveable-x.on-forge.com\n",
    );

    $options = SiteLogEntriesTable::siteIdOptions();

    expect($options)->toHaveKey('3075741')
        ->and($options['3075741'])->toContain('3075741')
        ->and($options['3075741'])->toContain('test.bestpropfirmsuk.com');
});

it('falls back to bare site_id when domains cannot be resolved', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'mismatch']);
    SiteLogEntry::factory()->for($server)->today()->create(['site_id' => '9999999']);

    $options = SiteLogEntriesTable::siteIdOptions();

    expect($options)->toBe(['9999999' => '9999999']);
});

it('renders domain tags on rows (when the Domains column is toggled on) via the DomainCache', function () {
    $server = Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
        'name' => 'edge-01',
    ]);
    SiteLogEntry::factory()->for($server)->count(1)->today()->create(['site_id' => '3075741']);

    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/test.bestpropfirmsuk.com\n",
    );

    Livewire::test(ListSiteLogEntries::class)
        ->toggleAllTableColumns()
        ->assertSuccessful()
        ->assertSeeText('test.bestpropfirmsuk.com');
});

it('hides secondary columns by default so the default view is just When + Server', function () {
    $server = Server::factory()->create();
    SiteLogEntry::factory()->for($server)->today()->create(['site_id' => '1111', 'host' => 'secret-host.example']);

    Livewire::test(ListSiteLogEntries::class)
        ->assertSuccessful()
        ->assertDontSee('secret-host.example'); // host column is hidden by default → row value not rendered
});
