<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

final readonly class ForgeSite
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $siteId,
        public string $siteConfPath,
        public string $managedPath,
        public bool $hasManaged,
        public ForgeSiteSettings $settings,
        public array $domains = [],
    ) {}
}
