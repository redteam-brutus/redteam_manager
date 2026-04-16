<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Exceptions\SshAuthException;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
    $this->manager = app(NginxManager::class);
});

function aServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'host_fingerprint' => 'fingerprint-known',
    ], $overrides));
}

it('lists and groups Nginx config files discovered on the server', function () {
    $listing = implode("\n", [
        '/etc/nginx/nginx.conf',
        '/etc/nginx/conf.d/redteam.conf',
        '/etc/nginx/conf.d/ssl.conf',
        '/etc/nginx/forge-conf/3075741/site.conf',
        '/etc/nginx/forge-conf/3075741/server/access.conf',
        '/etc/nginx/forge-conf/3075742/site.conf',
    ]);

    $this->fake->shouldReturn(0, $listing."\n");

    $server = aServer();

    $files = $this->manager->listFiles($server);

    expect($files)->toHaveCount(6)
        ->and(collect($files)->pluck('group')->all())->toBe([
            'main',
            'conf.d',
            'conf.d',
            'forge:3075741',
            'forge:3075741/server',
            'forge:3075742',
        ])
        ->and($files[0]->path)->toBe('/etc/nginx/nginx.conf')
        ->and($files[0]->relativePath)->toBe('nginx.conf');
});

it('reads a file inside /etc/nginx', function () {
    $this->fake->withFile('/etc/nginx/nginx.conf', "user www-data;\n");

    $content = $this->manager->readFile(aServer(), '/etc/nginx/nginx.conf');

    expect($content)->toBe("user www-data;\n");
});

it('rejects a path outside of /etc/nginx', function () {
    $this->manager->readFile(aServer(), '/etc/passwd');
})->throws(InvalidArgumentException::class, 'Path must be under /etc/nginx');

it('rejects a path containing parent traversal', function () {
    $this->manager->readFile(aServer(), '/etc/nginx/conf.d/../../passwd');
})->throws(InvalidArgumentException::class, 'parent traversal');

it('returns ok=true when nginx -t exits zero', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "nginx: configuration file /etc/nginx/nginx.conf test is successful\n");

    $result = $this->manager->validate(aServer());

    expect($result->ok)->toBeTrue()
        ->and($result->output)->toContain('successful');
});

it('returns ok=false with the output when nginx -t fails', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] invalid parameter in /etc/nginx/conf.d/site.conf:3\n");

    $result = $this->manager->validate(aServer());

    expect($result->ok)->toBeFalse()
        ->and($result->output)->toContain('invalid parameter');
});

it('refuses to reload when validate fails', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] syntax error\n");

    $result = $this->manager->reload(aServer());

    expect($result->ok)->toBeFalse()
        ->and($result->output)->toContain('syntax error');

    $systemctlCalls = array_filter(
        $this->fake->commands,
        fn (string $c): bool => str_contains($c, 'systemctl reload'),
    );

    expect($systemctlCalls)->toBeEmpty();
});

it('reloads nginx after a successful validate', function () {
    $this->fake
        ->shouldReturnForCommand('nginx -t', 0, "test is successful\n")
        ->shouldReturnForCommand('systemctl reload nginx', 0, '');

    $result = $this->manager->reload(aServer());

    expect($result->ok)->toBeTrue();

    $systemctlCalls = array_filter(
        $this->fake->commands,
        fn (string $c): bool => str_contains($c, 'systemctl reload'),
    );

    expect($systemctlCalls)->not->toBeEmpty();
});

it('routes privileged commands through sudo when the server has use_sudo enabled', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = aServer();
    $server->forceFill(['use_sudo' => true, 'sudo_password' => 'hunter2'])->save();

    $this->manager->validate($server);

    expect($this->fake->privilegedCommands)->not->toBeEmpty()
        ->and($this->fake->lastSudoPassword)->toBe('hunter2');
});

it('uses plain run for privileged commands when use_sudo is false', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->manager->validate(aServer(['use_sudo' => false]));

    expect($this->fake->privilegedCommands)->toBeEmpty()
        ->and($this->fake->lastSudoPassword)->toBeNull();
});

it('throws when use_sudo is enabled but no sudo password is set', function () {
    $server = aServer();
    $server->forceFill(['use_sudo' => true, 'sudo_password' => null])->save();

    $this->manager->validate($server);
})->throws(SshAuthException::class, 'no sudo password');
