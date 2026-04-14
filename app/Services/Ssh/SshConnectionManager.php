<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Enums\ConnectionStatus;
use App\Models\Server;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\CommandResult;
use App\Services\Ssh\Dto\ConnectionConfig;
use App\Services\Ssh\Dto\ConnectionTestResult;
use App\Services\Ssh\Exceptions\HostKeyMismatchException;
use App\Services\Ssh\Exceptions\SshAuthException;
use App\Services\Ssh\Exceptions\SshConnectionException;
use App\Services\Ssh\Exceptions\SshException;
use Throwable;

class SshConnectionManager
{
    public function __construct(private readonly SshClient $client) {}

    public function testConnection(Server $server): ConnectionTestResult
    {
        try {
            $session = $this->openAuthenticatedSession($server);
        } catch (HostKeyMismatchException $e) {
            $this->recordFailure($server, ConnectionStatus::HostMismatch, $e->getMessage());

            return new ConnectionTestResult(ConnectionStatus::HostMismatch, $e->getMessage());
        } catch (SshException $e) {
            $this->recordFailure($server, ConnectionStatus::Failed, $e->getMessage());

            return new ConnectionTestResult(ConnectionStatus::Failed, $e->getMessage());
        }

        try {
            $probe = $session->run('whoami && uname -a');
        } catch (SshException $e) {
            $session->disconnect();
            $this->recordFailure($server, ConnectionStatus::Failed, $e->getMessage());

            return new ConnectionTestResult(ConnectionStatus::Failed, $e->getMessage());
        }

        $session->disconnect();

        if (! $probe->succeeded()) {
            $message = trim($probe->stderr) !== ''
                ? trim($probe->stderr)
                : "Probe command exited with status {$probe->exitCode}.";

            $this->recordFailure($server, ConnectionStatus::Failed, $message);

            return new ConnectionTestResult(ConnectionStatus::Failed, $message);
        }

        $metadata = $this->parseProbe($probe);

        $server->forceFill([
            'last_connected_at' => now(),
            'last_connection_status' => ConnectionStatus::Success,
            'last_connection_message' => null,
            'metadata' => $metadata,
        ])->save();

        return new ConnectionTestResult(ConnectionStatus::Success, null, $metadata);
    }

    public function run(Server $server, string $command, int $timeoutSeconds = 10): CommandResult
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            return $session->run($command, $timeoutSeconds);
        } finally {
            $session->disconnect();
        }
    }

    public function runPrivileged(Server $server, string $command, int $timeoutSeconds = 10): CommandResult
    {
        if ($server->use_sudo && blank($server->sudo_password)) {
            throw new SshAuthException("Sudo is enabled on server #{$server->getKey()} but no sudo password is set.");
        }

        $session = $this->openAuthenticatedSession($server);

        try {
            if ($server->use_sudo) {
                return $session->runPrivileged($command, (string) $server->sudo_password, $timeoutSeconds);
            }

            return $session->run($command, $timeoutSeconds);
        } finally {
            $session->disconnect();
        }
    }

    public function readFile(Server $server, string $path): string
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            return $session->readFile($path);
        } finally {
            $session->disconnect();
        }
    }

    /**
     * @return list<string>
     */
    public function listFiles(Server $server, string $pattern): array
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            return $session->listFiles($pattern);
        } finally {
            $session->disconnect();
        }
    }

    public function writeFile(Server $server, string $path, string $content): void
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            $session->writeFile($path, $content);
        } finally {
            $session->disconnect();
        }
    }

    public function moveFile(Server $server, string $from, string $to): void
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            $session->moveFile($from, $to);
        } finally {
            $session->disconnect();
        }
    }

    public function deleteFile(Server $server, string $path): void
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            $session->deleteFile($path);
        } finally {
            $session->disconnect();
        }
    }

    public function fileExists(Server $server, string $path): bool
    {
        $session = $this->openAuthenticatedSession($server);

        try {
            return $session->fileExists($path);
        } finally {
            $session->disconnect();
        }
    }

    /**
     * Open a session, authenticate, verify host fingerprint (TOFU).
     *
     * phpseclib performs the SSH key exchange during login(), so the server's
     * host key is only readable after authenticate(). We verify the fingerprint
     * immediately after auth and before executing any command; a mismatch
     * triggers disconnect and refuses all further operations.
     *
     * @throws SshException
     */
    private function openAuthenticatedSession(Server $server): SshSession
    {
        $sshKey = $server->sshKey;

        if ($sshKey === null) {
            throw new SshAuthException("Server #{$server->getKey()} has no SSH key assigned.");
        }

        $session = $this->client->connect(new ConnectionConfig(
            host: $server->host,
            port: $server->port,
            username: $server->ssh_user,
        ));

        try {
            $session->authenticate($sshKey->private_key, $sshKey->passphrase);

            $fingerprint = $session->hostFingerprint();
            $this->verifyFingerprint($server, $fingerprint);
        } catch (Throwable $e) {
            $session->disconnect();

            if ($e instanceof SshException) {
                throw $e;
            }

            throw new SshConnectionException($e->getMessage(), previous: $e);
        }

        return $session;
    }

    private function verifyFingerprint(Server $server, string $observed): void
    {
        if ($server->host_fingerprint === null) {
            $server->forceFill(['host_fingerprint' => $observed])->save();

            return;
        }

        if (! hash_equals($server->host_fingerprint, $observed)) {
            throw new HostKeyMismatchException($server->host_fingerprint, $observed);
        }
    }

    private function recordFailure(Server $server, ConnectionStatus $status, string $message): void
    {
        $server->forceFill([
            'last_connection_status' => $status,
            'last_connection_message' => $message,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    private function parseProbe(CommandResult $result): array
    {
        $lines = preg_split('/\r?\n/', trim($result->stdout)) ?: [];

        return [
            'whoami' => trim($lines[0] ?? ''),
            'uname' => trim($lines[1] ?? ''),
        ];
    }
}
