<?php

declare(strict_types=1);

namespace App\Services\Ssh\Dto;

use App\Enums\ConnectionStatus;

final readonly class ConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ConnectionStatus $status,
        public ?string $message,
        public array $metadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === ConnectionStatus::Success;
    }
}
