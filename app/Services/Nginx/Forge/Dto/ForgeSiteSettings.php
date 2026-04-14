<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

final readonly class ForgeSiteSettings
{
    public function __construct(
        public bool $analyticsEnabled = false,
        public string $trackingTag = '</head>',
        public string $scriptBody = '',
        public bool $conditionalAccessLog = false,
        public string $accessLogPath = '',
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->analyticsEnabled && ! $this->conditionalAccessLog;
    }
}
