<?php

declare(strict_types=1);

namespace App\Services\Nginx\Logs\Dto;

final readonly class LogFile
{
    public function __construct(
        public string $path,
        public string $filename,
    ) {}
}
