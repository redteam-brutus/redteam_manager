<?php

declare(strict_types=1);

namespace App\Services\Ssh\Testing;

use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Exceptions\SshAuthException;

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

        return new CommandResult(
            stdout: $this->client->commandStdout,
            stderr: $this->client->commandStderr,
            exitCode: $this->client->commandExitCode,
        );
    }

    public function disconnect(): void
    {
        $this->client->disconnectCount++;
    }
}
