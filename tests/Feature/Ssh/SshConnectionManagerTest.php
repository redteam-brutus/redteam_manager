<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Models\Server;
use App\Models\SshKey;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\SshConnectionManager;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->fake);
    $this->manager = app(SshConnectionManager::class);
});

it('stores the observed fingerprint on the first successful connection (TOFU)', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-one')
        ->shouldReturn(0, "root\nLinux host 5.15\n");

    $server = Server::factory()->create([
        'host_fingerprint' => null,
        'ssh_key_id' => SshKey::factory(),
    ]);

    $result = $this->manager->testConnection($server);

    expect($result->status)->toBe(ConnectionStatus::Success)
        ->and($server->refresh()->host_fingerprint)->toBe('fingerprint-one')
        ->and($server->last_connection_status)->toBe(ConnectionStatus::Success)
        ->and($server->metadata)->toMatchArray(['whoami' => 'root', 'uname' => 'Linux host 5.15']);
});

it('refuses the connection and marks host mismatch when the fingerprint changes', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-attacker')
        ->shouldReturn(0, "root\nLinux\n");

    $server = Server::factory()->create([
        'host_fingerprint' => 'fingerprint-trusted',
    ]);

    $result = $this->manager->testConnection($server);

    expect($result->status)->toBe(ConnectionStatus::HostMismatch)
        ->and($server->refresh()->host_fingerprint)->toBe('fingerprint-trusted')
        ->and($server->last_connection_status)->toBe(ConnectionStatus::HostMismatch)
        ->and($server->last_connection_message)->toContain('Host key mismatch');

    expect($this->fake->commands)->toBeEmpty();
});

it('records a failure when authentication fails and preserves prior metadata', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldFailAuth('Permission denied (publickey).');

    $server = Server::factory()->connected()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);
    $priorMetadata = $server->metadata;

    $result = $this->manager->testConnection($server);

    expect($result->status)->toBe(ConnectionStatus::Failed)
        ->and($server->refresh()->last_connection_status)->toBe(ConnectionStatus::Failed)
        ->and($server->last_connection_message)->toContain('Permission denied')
        ->and($server->metadata)->toBe($priorMetadata);
});

it('records a failure when the probe command exits non-zero', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldReturn(127, '', 'bash: uname: command not found');

    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $result = $this->manager->testConnection($server);

    expect($result->status)->toBe(ConnectionStatus::Failed)
        ->and($server->refresh()->last_connection_message)->toContain('bash: uname: command not found');
});

it('records a failure when the server cannot be reached', function () {
    $this->fake->shouldFailConnect('Network unreachable');

    $server = Server::factory()->create();

    $result = $this->manager->testConnection($server);

    expect($result->status)->toBe(ConnectionStatus::Failed)
        ->and($server->refresh()->last_connection_message)->toContain('Network unreachable');
});

it('runs an arbitrary command and returns the result', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldReturn(0, "nginx version: nginx/1.24.0\n");

    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $result = $this->manager->run($server, 'nginx -v');

    expect($result->succeeded())->toBeTrue()
        ->and($result->stdout)->toContain('nginx/1.24.0')
        ->and($this->fake->commands)->toContain('nginx -v');
});

it('disconnects the session after every connection attempt', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldReturn(0, "root\nLinux\n");

    $server = Server::factory()->create(['host_fingerprint' => 'fingerprint-known']);

    $this->manager->testConnection($server);

    expect($this->fake->disconnectCount)->toBeGreaterThanOrEqual(1);
});
