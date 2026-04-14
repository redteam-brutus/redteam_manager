<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Dto\ConnectionConfig;
use App\Services\Ssh\Exceptions\SshAuthException;
use App\Services\Ssh\Exceptions\SshCommandException;
use App\Services\Ssh\Exceptions\SshConnectionException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

class PhpSecLibSshSession implements SshSession
{
    private bool $authenticated = false;

    private ?PrivateKey $privateKey = null;

    private ?SFTP $sftp = null;

    public function __construct(
        private readonly SSH2 $ssh,
        private readonly ConnectionConfig $config,
    ) {}

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
        $this->privateKey = $key;
    }

    public function run(string $command, int $timeoutSeconds = 10): CommandResult
    {
        $this->ensureAuthenticated();

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

    public function runPrivileged(string $command, string $sudoPassword, int $timeoutSeconds = 10): CommandResult
    {
        $escapedPassword = $this->singleQuoteEscape($sudoPassword);
        $escapedCommand = $this->singleQuoteEscape($command);

        $wrapped = sprintf(
            "printf '%%s\\n' '%s' | sudo -S -p '' -- /bin/sh -c '%s'",
            $escapedPassword,
            $escapedCommand,
        );

        return $this->run($wrapped, $timeoutSeconds);
    }

    public function readFile(string $path): string
    {
        $content = $this->sftp()->get($path);

        if ($content === false) {
            throw new SshCommandException("Failed to read remote file: {$path}");
        }

        return is_string($content) ? $content : '';
    }

    public function listFiles(string $pattern): array
    {
        $directory = dirname($pattern);
        $glob = basename($pattern);

        $entries = $this->sftp()->nlist($directory);

        if ($entries === false) {
            return [];
        }

        $matches = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! fnmatch($glob, $entry)) {
                continue;
            }

            $fullPath = $directory.'/'.$entry;

            if ($this->sftp()->is_file($fullPath)) {
                $matches[] = $fullPath;
            }
        }

        sort($matches);

        return $matches;
    }

    public function disconnect(): void
    {
        if ($this->sftp !== null && $this->sftp->isConnected()) {
            $this->sftp->disconnect();
        }

        $this->sftp = null;

        if ($this->ssh->isConnected()) {
            $this->ssh->disconnect();
        }

        $this->authenticated = false;
        $this->privateKey = null;
    }

    private function sftp(): SFTP
    {
        $this->ensureAuthenticated();

        if ($this->sftp !== null && $this->sftp->isConnected()) {
            return $this->sftp;
        }

        try {
            $sftp = new SFTP($this->config->host, $this->config->port, $this->config->connectTimeoutSeconds);

            if ($this->privateKey === null) {
                throw new SshConnectionException('Missing private key for SFTP session.');
            }

            if (! $sftp->login($this->config->username, $this->privateKey)) {
                throw new SshAuthException("SFTP authentication rejected for {$this->config->username}@{$this->config->host}.");
            }
        } catch (Throwable $e) {
            if ($e instanceof SshConnectionException || $e instanceof SshAuthException) {
                throw $e;
            }

            throw new SshConnectionException(
                "Failed to open SFTP channel: {$e->getMessage()}",
                previous: $e,
            );
        }

        return $this->sftp = $sftp;
    }

    private function ensureAuthenticated(): void
    {
        if (! $this->authenticated) {
            throw new SshCommandException('Cannot perform operation before authentication.');
        }
    }

    private function singleQuoteEscape(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
