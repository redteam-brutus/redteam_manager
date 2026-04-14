<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
    $this->manager = app(NginxManager::class);
});

function aSavableServer(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);
}

function seedFile(FakeSshClient $fake, string $path, string $content): string
{
    $fake->withFile($path, $content);
    $hash = hash('sha256', $content);
    $fake->shouldReturnForCommand('sha256sum '.escapeshellarg($path), 0, $hash."\n");

    return $hash;
}

it('writes tmp, backup, renames, validates and returns saved', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $original = "server { listen 80; }\n";
    $updated = "server { listen 443; }\n";
    $hash = seedFile($this->fake, $path, $original);
    $this->fake->shouldReturnForCommand('nginx -t', 0, "config ok\n");

    $result = $this->manager->saveFile(aSavableServer(), $path, $updated, $hash);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('saved')
        ->and($result->backupPath)->toStartWith($path.'.bak.')
        ->and($this->fake->files[$path])->toBe($updated);

    $writePaths = array_column($this->fake->writes, 'path');
    expect($writePaths)->toContain($path.'.redteam.tmp')
        ->and($writePaths)->toContain($result->backupPath);

    $moveTargets = array_column($this->fake->moves, 'to');
    expect($moveTargets)->toContain($path);
});

it('returns stale and writes nothing when the hash drifted', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    seedFile($this->fake, $path, "current\n");

    $result = $this->manager->saveFile(aSavableServer(), $path, "new\n", 'not-the-real-hash');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('stale')
        ->and($this->fake->writes)->toBeEmpty()
        ->and($this->fake->moves)->toBeEmpty();
});

it('rolls back from the backup when nginx -t fails after save', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $original = "server { listen 80; }\n";
    $broken = "server { bogus;\n";
    $hash = seedFile($this->fake, $path, $original);
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] unexpected\n");

    $result = $this->manager->saveFile(aSavableServer(), $path, $broken, $hash);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('invalid_config')
        ->and($result->output)->toContain('unexpected')
        ->and($this->fake->files[$path])->toBe($original);

    $restoredFromBackup = collect($this->fake->moves)->last();
    expect($restoredFromBackup['to'])->toBe($path)
        ->and($restoredFromBackup['from'])->toStartWith($path.'.bak.');
});

it('returns io_error when the tmp write fails', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    seedFile($this->fake, $path, "current\n");
    $this->fake->shouldFailWrite('Disk full.');

    $result = $this->manager->saveFile(aSavableServer(), $path, "new\n", hash('sha256', "current\n"));

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('io_error')
        ->and($this->fake->moves)->toBeEmpty();
});

it('prunes backup files beyond the newest 10 after a successful save', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $original = "a\n";
    $hash = seedFile($this->fake, $path, $original);
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    // seed 15 existing backups with descending timestamps
    for ($i = 0; $i < 15; $i++) {
        $backup = $path.'.bak.2026010112000'.$i;
        $this->fake->withFile($backup, "old-{$i}");
    }

    $this->manager->saveFile(aSavableServer(), $path, "new\n", $hash);

    $remainingBackups = array_values(array_filter(
        array_keys($this->fake->files),
        fn (string $p): bool => str_starts_with($p, $path.'.bak.'),
    ));

    expect(count($remainingBackups))->toBe(10);
});

it('hashes a file via sha256sum', function () {
    $path = '/etc/nginx/nginx.conf';
    $hash = seedFile($this->fake, $path, "user www-data;\n");

    expect($this->manager->fileHash(aSavableServer(), $path))->toBe($hash);
});

it('rejects saveFile for paths outside /etc/nginx', function () {
    $this->manager->saveFile(aSavableServer(), '/etc/passwd', 'evil', 'any');
})->throws(InvalidArgumentException::class, 'Path must be under /etc/nginx');
