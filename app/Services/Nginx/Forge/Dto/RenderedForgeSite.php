<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

final readonly class RenderedForgeSite
{
    public function __construct(
        public string $httpContext = '',
        public string $serverContext = '',
    ) {}

    public function combined(): string
    {
        return $this->httpContext."\n".$this->serverContext;
    }
}
