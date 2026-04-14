<?php

declare(strict_types=1);

namespace App\Services\Ssh\Contracts;

use App\Services\Ssh\Dto\ConnectionConfig;

interface SshClient
{
    public function connect(ConnectionConfig $config): SshSession;
}
