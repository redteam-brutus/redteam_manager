<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Nginx\Logs\LogViewer;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
    $this->viewer = app(LogViewer::class);
});

function aLoggedServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'host_fingerprint' => 'fingerprint-known',
    ], $overrides));
}

it('lists log files from the ls output', function () {
    $this->fake->shouldReturn(0, "/var/log/nginx/access.log\n/var/log/nginx/error.log\n/var/log/nginx/fbclid.log\n");

    $logs = $this->viewer->list(aLoggedServer());

    expect($logs)->toHaveCount(3)
        ->and($logs[0]->path)->toBe('/var/log/nginx/access.log')
        ->and($logs[0]->filename)->toBe('access.log')
        ->and($logs[2]->filename)->toBe('fbclid.log');
});

it('drops ls lines that do not match the log-root pattern', function () {
    $this->fake->shouldReturn(0, "/var/log/nginx/access.log\n/etc/passwd\nnot-a-path\n");

    $logs = $this->viewer->list(aLoggedServer());

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->path)->toBe('/var/log/nginx/access.log');
});

it('runs plain tail when the server does not use sudo', function () {
    $this->fake->shouldReturn(0, "line1\nline2\n");

    $this->viewer->tail(aLoggedServer(['use_sudo' => false]), '/var/log/nginx/access.log');

    $hasTail = collect($this->fake->commands)->contains(
        fn (string $c): bool => str_starts_with($c, 'tail -n 500 ')
            && str_contains($c, '/var/log/nginx/access.log'),
    );

    expect($hasTail)->toBeTrue()
        ->and($this->fake->privilegedCommands)->toBeEmpty();
});

it('routes tail through sudo when the server uses sudo', function () {
    $this->fake->shouldReturn(0, "line1\n");

    $server = aLoggedServer();
    $server->forceFill(['use_sudo' => true, 'sudo_password' => 'hunter2'])->save();

    $this->viewer->tail($server, '/var/log/nginx/access.log');

    expect($this->fake->privilegedCommands)->not->toBeEmpty()
        ->and($this->fake->lastSudoPassword)->toBe('hunter2');
});

it('rejects a path outside /var/log/nginx', function () {
    $this->viewer->tail(aLoggedServer(), '/etc/passwd');
})->throws(InvalidArgumentException::class, 'Log path must be under /var/log/nginx');

it('rejects a traversal path', function () {
    $this->viewer->tail(aLoggedServer(), '/var/log/nginx/../../etc/passwd');
})->throws(InvalidArgumentException::class, 'Log path must be under /var/log/nginx');

it('clamps absurd line counts to 2000', function () {
    $this->fake->shouldReturn(0, "line1\n");

    $this->viewer->tail(aLoggedServer(), '/var/log/nginx/access.log', 100_000);

    $clamped = collect($this->fake->commands)->contains(
        fn (string $c): bool => str_starts_with($c, 'tail -n 2000 '),
    );

    expect($clamped)->toBeTrue();
});
