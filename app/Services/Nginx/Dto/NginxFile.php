<?php

declare(strict_types=1);

namespace App\Services\Nginx\Dto;

final readonly class NginxFile
{
    public function __construct(
        public string $path,
        public string $group,
        public string $relativePath,
    ) {}
}
