<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageServerNginx;
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

function serverForNginxPage(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);
}

it('loads the file list on mount', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/nginx.conf\n/etc/nginx/conf.d/site.conf\n");

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->assertSet('files.0.path', '/etc/nginx/nginx.conf')
        ->assertSet('files.1.path', '/etc/nginx/conf.d/site.conf');
});

it('loads the selected file content', function () {
    $this->fake
        ->shouldReturn(0, "/etc/nginx/nginx.conf\n")
        ->withFile('/etc/nginx/nginx.conf', "worker_processes auto;\n");

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', '/etc/nginx/nginx.conf')
        ->assertSet('selectedPath', '/etc/nginx/nginx.conf')
        ->assertSet('fileContent', "worker_processes auto;\n");
});

it('notifies success on validate when nginx -t passes', function () {
    $this->fake
        ->shouldReturn(0, "/etc/nginx/nginx.conf\n")
        ->shouldReturnForCommand('nginx -t', 0, "test is successful\n");

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->callAction('validate')
        ->assertNotified('Config valid');
});

it('notifies failure on validate when nginx -t fails', function () {
    $this->fake
        ->shouldReturn(0, "/etc/nginx/nginx.conf\n")
        ->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] unknown directive\n");

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->callAction('validate')
        ->assertNotified('Config invalid');
});

it('reload refuses and notifies when validate fails', function () {
    $this->fake
        ->shouldReturn(0, "/etc/nginx/nginx.conf\n")
        ->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] unknown directive\n");

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->callAction('reload')
        ->assertNotified('Reload refused');

    $systemctlCalls = array_filter(
        $this->fake->commands,
        fn (string $c): bool => str_contains($c, 'systemctl reload'),
    );

    expect($systemctlCalls)->toBeEmpty();
});

it('reload succeeds and notifies on success', function () {
    $this->fake
        ->shouldReturn(0, "/etc/nginx/nginx.conf\n")
        ->shouldReturnForCommand('nginx -t', 0, "test is successful\n")
        ->shouldReturnForCommand('systemctl reload nginx', 0, '');

    $server = serverForNginxPage();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->callAction('reload')
        ->assertNotified('Nginx reloaded');
});
