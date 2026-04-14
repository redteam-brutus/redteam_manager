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
        public bool $gateNotBot = false,
        public bool $gateHasFbclid = false,
        public bool $gateIsTargetCountry = false,
        public bool $gateIsTargetPage = false,
    ) {}

    public function hasAnyGate(): bool
    {
        return $this->gateNotBot
            || $this->gateHasFbclid
            || $this->gateIsTargetCountry
            || $this->gateIsTargetPage;
    }

    public function isEmpty(): bool
    {
        return ! $this->analyticsEnabled && ! $this->conditionalAccessLog;
    }
}
