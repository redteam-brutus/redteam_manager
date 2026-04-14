<?php

declare(strict_types=1);

namespace App\Services\Ssh\Dto;

final readonly class CommandResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
