<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerSite;
use App\Services\Nginx\DomainCache;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

it('reads domains entirely from the server_sites table on for() — never opens an SSH session', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);
    ServerSite::query()->create([
        'server_id' => $server->id,
        'site_id' => '3075741',
        'domains' => ['edge-one.example.com', 'edge-two.example.com'],
        'synced_at' => now(),
    ]);

    $map = app(DomainCache::class)->for($server);

    expect($map)->toBe(['3075741' => ['edge-one.example.com', 'edge-two.example.com']])
        ->and($this->fake->commands)->toBeEmpty();
});

it('upserts the live forge layout into server_sites on sync() and prunes removed sites', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    // Pre-existing site that no longer appears in nginx — must be pruned.
    ServerSite::query()->create([
        'server_id' => $server->id,
        'site_id' => 'gone',
        'domains' => ['old.example.com'],
        'synced_at' => now(),
    ]);

    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/a.example.com\n/etc/nginx/forge-conf/3075741/b.example.com\n",
    );

    app(DomainCache::class)->sync($server);

    expect(ServerSite::query()->where('server_id', $server->id)->pluck('site_id')->all())
        ->toBe(['3075741']);

    $row = ServerSite::query()->where('server_id', $server->id)->where('site_id', '3075741')->firstOrFail();
    expect($row->domains)->toBe(['a.example.com', 'b.example.com']);
});

it('leaves existing rows untouched when SSH enumeration fails so the dashboard keeps serving last-known domains', function () {
    $server = Server::factory()->create(['host_fingerprint' => 'mismatch']);

    ServerSite::query()->create([
        'server_id' => $server->id,
        'site_id' => '3075741',
        'domains' => ['stable.example.com'],
        'synced_at' => now(),
    ]);

    app(DomainCache::class)->sync($server);

    expect(ServerSite::query()->where('server_id', $server->id)->where('site_id', '3075741')->value('domains'))
        ->toBe(['stable.example.com']);
});
