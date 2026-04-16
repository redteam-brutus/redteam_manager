<?php

declare(strict_types=1);

namespace App\Services\Nginx\Dto;

final readonly class NginxSaveResult
{
    public function __construct(
        public bool $ok,
        public string $status,
        public string $output,
        public ?string $backupPath = null,
    ) {}
}
