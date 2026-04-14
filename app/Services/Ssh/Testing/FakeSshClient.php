<?php

declare(strict_types=1);

namespace App\Services\Ssh\Testing;

use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Dto\ConnectionConfig;
use App\Services\Ssh\Exceptions\SshConnectionException;

class FakeSshClient implements SshClient
{
    public string $hostFingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public ?string $connectError = null;

    public ?string $authError = null;

    public int $commandExitCode = 0;

    public string $commandStdout = '';

    public string $commandStderr = '';

    public ?ConnectionConfig $lastConfig = null;

    /** @var list<string> */
    public array $commands = [];

    /** @var list<array{command: string, password: string}> */
    public array $privilegedCommands = [];

    public ?string $lastSudoPassword = null;

    public int $disconnectCount = 0;

    /** @var array<string, CommandResult> */
    public array $responseByMatch = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, list<string>> */
    public array $listings = [];

    public function withHostFingerprint(string $fingerprint): self
    {
        $this->hostFingerprint = $fingerprint;

        return $this;
    }

    public function shouldFailConnect(string $message): self
    {
        $this->connectError = $message;

        return $this;
    }

    public function shouldFailAuth(string $message): self
    {
        $this->authError = $message;

        return $this;
    }

    public function shouldReturn(int $exitCode, string $stdout = '', string $stderr = ''): self
    {
        $this->commandExitCode = $exitCode;
        $this->commandStdout = $stdout;
        $this->commandStderr = $stderr;

        return $this;
    }

    public function shouldReturnForCommand(string $match, int $exitCode, string $stdout = '', string $stderr = ''): self
    {
        $this->responseByMatch[$match] = new CommandResult($stdout, $stderr, $exitCode);

        return $this;
    }

    public function withFile(string $path, string $content): self
    {
        $this->files[$path] = $content;

        return $this;
    }

    /**
     * @param  list<string>  $paths
     */
    public function withListing(string $pattern, array $paths): self
    {
        $this->listings[$pattern] = $paths;

        return $this;
    }

    public function connect(ConnectionConfig $config): SshSession
    {
        $this->lastConfig = $config;

        if ($this->connectError !== null) {
            throw new SshConnectionException($this->connectError);
        }

        return new FakeSshSession($this);
    }

    public function resolveResponse(string $command): CommandResult
    {
        foreach ($this->responseByMatch as $match => $response) {
            if (str_contains($command, $match)) {
                return $response;
            }
        }

        return new CommandResult($this->commandStdout, $this->commandStderr, $this->commandExitCode);
    }
}
