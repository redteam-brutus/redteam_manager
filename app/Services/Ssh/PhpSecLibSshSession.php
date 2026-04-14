<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Dto\ConnectionConfig;
use App\Services\Ssh\Exceptions\SshAuthException;
use App\Services\Ssh\Exceptions\SshCommandException;
use App\Services\Ssh\Exceptions\SshConnectionException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

class PhpSecLibSshSession implements SshSession
{
    private bool $authenticated = false;

    public function __construct(
        private readonly SSH2 $ssh,
        private readonly ConnectionConfig $config,
    ) {}

    /**
     * Returns SHA256 of the server's public host key.
     *
     * phpseclib only populates the host key after the SSH key exchange, which
     * happens during authenticate(). Callers should verify the fingerprint
     * between authenticate() and run() to gate remote command execution.
     */
    public function hostFingerprint(): string
    {
        $hostKey = $this->ssh->getServerPublicHostKey();

        if ($hostKey === false || $hostKey === '') {
            throw new SshConnectionException(
                'Host key unavailable; call authenticate() before reading the fingerprint.'
            );
        }

        return hash('sha256', $hostKey);
    }

    public function authenticate(string $privateKey, ?string $passphrase = null): void
    {
        try {
            $key = PublicKeyLoader::loadPrivateKey($privateKey, $passphrase ?? false);
        } catch (Throwable $e) {
            throw new SshAuthException(
                "Failed to load private key: {$e->getMessage()}",
                previous: $e,
            );
        }

        try {
            $ok = $this->ssh->login($this->config->username, $key);
        } catch (Throwable $e) {
            throw new SshAuthException(
                "SSH authentication failed for {$this->config->username}@{$this->config->host}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($ok !== true) {
            throw new SshAuthException(
                "SSH authentication rejected for {$this->config->username}@{$this->config->host}."
            );
        }

        $this->authenticated = true;
    }

    public function run(string $command, int $timeoutSeconds = 10): CommandResult
    {
        if (! $this->authenticated) {
            throw new SshCommandException('Cannot run command before authentication.');
        }

        $this->ssh->setTimeout($timeoutSeconds);
        $this->ssh->enableQuietMode();

        try {
            $stdout = $this->ssh->exec($command);
        } catch (Throwable $e) {
            throw new SshCommandException(
                "Command execution failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($stdout === false) {
            throw new SshCommandException('Command execution returned no channel.');
        }

        $stderr = $this->ssh->getStdError();
        $exitCode = $this->ssh->getExitStatus();

        return new CommandResult(
            stdout: is_string($stdout) ? $stdout : '',
            stderr: is_string($stderr) ? $stderr : '',
            exitCode: is_int($exitCode) ? $exitCode : -1,
        );
    }

    public function disconnect(): void
    {
        if ($this->ssh->isConnected()) {
            $this->ssh->disconnect();
        }

        $this->authenticated = false;
    }
}
