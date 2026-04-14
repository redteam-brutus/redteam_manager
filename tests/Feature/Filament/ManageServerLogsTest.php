<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageServerLogs;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

function serverForLogsPage(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'host_fingerprint' => 'fingerprint-known',
    ], $overrides));
}

it('discovers logs on mount and auto-selects the first one', function () {
    $this->fake->shouldReturnForCommand('ls -1 /var/log/nginx', 0, "/var/log/nginx/access.log\n/var/log/nginx/error.log\n");
    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "hello\nworld\n");

    $server = serverForLogsPage();

    Livewire::test(ManageServerLogs::class, ['record' => $server->id])
        ->assertSet('logs.0.path', '/var/log/nginx/access.log')
        ->assertSet('logs.1.path', '/var/log/nginx/error.log')
        ->assertSet('selectedPath', '/var/log/nginx/access.log')
        ->assertSet('lines', ['hello', 'world']);
});

it('selectLog refreshes the lines from a different file', function () {
    $this->fake->shouldReturnForCommand('ls -1 /var/log/nginx', 0, "/var/log/nginx/access.log\n/var/log/nginx/error.log\n");
    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "first\nsecond\n");

    $server = serverForLogsPage();

    Livewire::test(ManageServerLogs::class, ['record' => $server->id])
        ->call('selectLog', '/var/log/nginx/error.log')
        ->assertSet('selectedPath', '/var/log/nginx/error.log')
        ->assertSet('lines', ['first', 'second']);
});

it('refuses to select an unknown log path', function () {
    $this->fake->shouldReturnForCommand('ls -1 /var/log/nginx', 0, "/var/log/nginx/access.log\n");
    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "x\n");

    $server = serverForLogsPage();

    Livewire::test(ManageServerLogs::class, ['record' => $server->id])
        ->call('selectLog', '/etc/passwd')
        ->assertSet('selectedPath', '/var/log/nginx/access.log');
});

it('tick while paused skips the SSH call', function () {
    $this->fake->shouldReturnForCommand('ls -1 /var/log/nginx', 0, "/var/log/nginx/access.log\n");
    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "initial\n");

    $server = serverForLogsPage();

    $component = Livewire::test(ManageServerLogs::class, ['record' => $server->id]);

    $commandsBefore = count($this->fake->commands);

    $component->call('togglePause')
        ->assertSet('paused', true)
        ->call('tick');

    expect(count($this->fake->commands))->toBe($commandsBefore);
});

it('tick while live re-fetches the tail', function () {
    $this->fake->shouldReturnForCommand('ls -1 /var/log/nginx', 0, "/var/log/nginx/access.log\n");
    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "first\n");

    $server = serverForLogsPage();

    $component = Livewire::test(ManageServerLogs::class, ['record' => $server->id])
        ->assertSet('lines', ['first']);

    $this->fake->shouldReturnForCommand('tail -n 500 ', 0, "second\nthird\n");

    $component->call('tick')->assertSet('lines', ['second', 'third']);
});
