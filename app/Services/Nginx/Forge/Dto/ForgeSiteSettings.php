<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

final readonly class ForgeSiteSettings
{
    public const DEFAULT_SOCIAL_REFERER_HOSTS = [
        'facebook.com',
        'fb.me',
        'instagram.com',
        'm.facebook.com',
        'l.facebook.com',
        'lm.facebook.com',
    ];

    /**
     * @param  list<string>  $targetCountries  2-letter uppercase CF country codes, e.g. ['IL', 'EG'].
     * @param  list<string>  $targetPages  URI regex bodies (without the ~* prefix), e.g. ['^/page-1/'].
     * @param  list<string>  $socialRefererHosts  Hosts that count as entry-proof referers (OR with fbclid).
     * @param  list<ForgeSiteCustomLog>  $customLogs  User-defined per-site log streams (slug → conditions).
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
        public array $socialRefererHosts = [],
        public array $customLogs = [],
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
