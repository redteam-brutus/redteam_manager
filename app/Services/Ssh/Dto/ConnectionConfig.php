<?php

declare(strict_types=1);

namespace App\Services\Ssh\Dto;

final readonly class ConnectionConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public int $connectTimeoutSeconds = 10,
    ) {}
}
