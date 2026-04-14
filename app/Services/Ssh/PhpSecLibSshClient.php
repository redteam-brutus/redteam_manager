<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Contracts\SshSession;
use App\Services\Ssh\Dto\ConnectionConfig;
use App\Services\Ssh\Exceptions\SshConnectionException;
use phpseclib3\Net\SSH2;
use Throwable;

class PhpSecLibSshClient implements SshClient
{
    public function connect(ConnectionConfig $config): SshSession
    {
        try {
            $ssh = new SSH2($config->host, $config->port, $config->connectTimeoutSeconds);
        } catch (Throwable $e) {
            throw new SshConnectionException(
                "Failed to connect to {$config->host}:{$config->port}: {$e->getMessage()}",
                previous: $e,
            );
        }

        return new PhpSecLibSshSession($ssh, $config);
    }
}
