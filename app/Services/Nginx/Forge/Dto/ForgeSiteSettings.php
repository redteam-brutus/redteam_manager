<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

final readonly class ForgeSiteSettings
{
    /**
     * @param  list<string>  $targetCountries  2-letter uppercase CF country codes, e.g. ['IL', 'EG'].
     * @param  list<string>  $targetPages  URI regex bodies (without the ~* prefix), e.g. ['^/page-1/'].
     */
    public function __construct(
        public bool $analyticsEnabled = false,
        public string $trackingTag = '</head>',
        public string $scriptBody = '',
        public bool $siteLoggingEnabled = false,
        public bool $gateNotBot = false,
        public bool $gateHasFbclid = false,
        public bool $gateIsTargetCountry = false,
        public bool $gateIsTargetPage = false,
        public array $targetCountries = [],
        public array $targetPages = [],
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
        return ! $this->analyticsEnabled && ! $this->siteLoggingEnabled;
    }
}
