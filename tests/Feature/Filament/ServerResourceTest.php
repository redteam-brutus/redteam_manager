<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Filament\Resources\Servers\Actions\TestConnectionAction;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->fake = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->fake);
});

it('creates a server via the form', function () {
    $sshKey = SshKey::factory()->create();

    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'edge-1',
            'host' => '10.0.0.1',
            'port' => 22,
            'ssh_user' => 'root',
            'ssh_key_id' => $sshKey->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('servers', [
        'name' => 'edge-1',
        'host' => '10.0.0.1',
        'ssh_key_id' => $sshKey->id,
    ]);
});

it('runs Test Connection and shows a success notification on success', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldReturn(0, "root\nLinux edge\n");

    $server = Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);

    Livewire::test(ListServers::class)
        ->callTableAction(TestConnectionAction::getDefaultName(), $server)
        ->assertNotified('SSH connection succeeded');

    expect($server->refresh()->last_connection_status)->toBe(ConnectionStatus::Success);
});

it('runs Test Connection and shows a danger notification on failure', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-known')
        ->shouldFailAuth('Permission denied (publickey).');

    $server = Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);

    Livewire::test(ListServers::class)
        ->callTableAction(TestConnectionAction::getDefaultName(), $server)
        ->assertNotified('SSH connection failed');

    expect($server->refresh()->last_connection_status)->toBe(ConnectionStatus::Failed);
});

it('marks a host key mismatch with a warning notification', function () {
    $this->fake
        ->withHostFingerprint('fingerprint-attacker')
        ->shouldReturn(0, "root\nLinux\n");

    $server = Server::factory()->create([
        'host_fingerprint' => 'fingerprint-trusted',
    ]);

    Livewire::test(ListServers::class)
        ->callTableAction(TestConnectionAction::getDefaultName(), $server)
        ->assertNotified('Host key mismatch');

    expect($server->refresh()->last_connection_status)->toBe(ConnectionStatus::HostMismatch);
});

it('creates a server with sudo enabled and stores an encrypted password', function () {
    $sshKey = SshKey::factory()->create();

    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'edge-sudo',
            'host' => '10.0.0.2',
            'port' => 22,
            'ssh_user' => 'ubuntu',
            'ssh_key_id' => $sshKey->id,
            'use_sudo' => true,
            'sudo_password' => 'hunter2',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = Server::firstWhere('name', 'edge-sudo');

    expect($server->use_sudo)->toBeTrue()
        ->and($server->sudo_password)->toBe('hunter2');

    $raw = DB::table('servers')->where('id', $server->id)->value('sudo_password');

    expect($raw)->not->toBe('hunter2');
});

it('preserves the existing sudo password when the edit form leaves it blank', function () {
    $server = Server::factory()->withSudo('original-pass')->create();

    Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
        ->fillForm([
            'sudo_password' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($server->refresh()->sudo_password)->toBe('original-pass');
});
