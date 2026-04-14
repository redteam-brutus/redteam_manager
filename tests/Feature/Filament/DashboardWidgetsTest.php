<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\TrafficStatsWidget;
use App\Filament\Widgets\TrafficTableWidget;
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

it('TrafficStatsWidget renders the four top-line metrics', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    SiteLogEntry::factory()->for($server)->count(5)->today()->create(['site_id' => '3075741']);
    SiteLogEntry::factory()->for($server)->count(2)->today()->gated()->create(['site_id' => '3075741']);
    SiteLogEntry::factory()->for($server)->count(3)->today()->withFbclid()->create(['site_id' => '3075741']);
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    Livewire::test(TrafficStatsWidget::class)
        ->assertSeeText('Visits today')
        ->assertSeeText('Gate hits today')
        ->assertSeeText('FBCLID hits today')
        ->assertSeeText('Active sites today');
});

it('TrafficTableWidget renders one row per active site', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known', 'name' => 'edge-01']);
    SiteLogEntry::factory()->for($server)->count(5)->today()->create(['site_id' => '3075741']);
    SiteLogEntry::factory()->for($server)->count(2)->today()->create(['site_id' => '3075742']);
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');

    Livewire::test(TrafficTableWidget::class)
        ->assertSeeText('3075741')
        ->assertSeeText('3075742')
        ->assertSeeText('edge-01');
});

it('RecentActivityWidget renders the last edits with kind badges', function () {
    Server::factory()->create(['host_fingerprint' => 'fingerprint-known', 'name' => 'edge-01']);

    $this->fake->shouldReturnForCommand(
        '-printf',
        0,
        "1760451000 /etc/nginx/forge-conf/3075741/server/redteam-analytics.conf\n".
        "1760450000 /etc/nginx/conf.d/redteam-forge-3075742.conf\n",
    );
    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/test.example.com\n",
    );

    Livewire::test(RecentActivityWidget::class)
        ->assertSeeText('3075741')
        ->assertSeeText('3075742')
        ->assertSeeText('edge-01');
});
