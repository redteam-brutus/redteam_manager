<?php

declare(strict_types=1);

namespace App\Services\Ssh\Testing;

use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Exceptions\SshAuthException;
use App\Services\Ssh\Exceptions\SshCommandException;

class FakeSshSession implements SshSession
{
    public function __construct(private readonly FakeSshClient $client) {}

    public function hostFingerprint(): string
    {
        return $this->client->hostFingerprint;
    }

    public function authenticate(string $privateKey, ?string $passphrase = null): void
    {
        if ($this->client->authError !== null) {
            throw new SshAuthException($this->client->authError);
        }
    }

    public function run(string $command, int $timeoutSeconds = 10): CommandResult
    {
        $this->client->commands[] = $command;

        return $this->client->resolveResponse($command);
    }

    public function runPrivileged(string $command, string $sudoPassword, int $timeoutSeconds = 10): CommandResult
    {
        $this->client->commands[] = $command;
        $this->client->privilegedCommands[] = ['command' => $command, 'password' => $sudoPassword];
        $this->client->lastSudoPassword = $sudoPassword;

        return $this->client->resolveResponse($command);
    }

    public function readFile(string $path): string
    {
        if (! array_key_exists($path, $this->client->files)) {
            throw new SshCommandException("No canned content for path: {$path}");
        }

        return $this->client->files[$path];
    }

    public function listFiles(string $pattern): array
    {
        return $this->client->listings[$pattern] ?? [];
    }

    public function disconnect(): void
    {
        $this->client->disconnectCount++;
    }
}
