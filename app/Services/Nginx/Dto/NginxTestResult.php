<?php

declare(strict_types=1);

namespace App\Services\Nginx\Dto;

final readonly class NginxTestResult
{
    public function __construct(
        public bool $ok,
        public string $output,
    ) {}
}
