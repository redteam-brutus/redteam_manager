<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Dto;

final readonly class SiteTrafficRow
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public int $serverId,
        public string $serverName,
        public string $siteId,
        public ?string $firstDomain,
        public array $domains,
        public int $visits,
        public int $gateHits,
        public int $fbclidHits,
    ) {}

    public function domainLabel(): string
    {
        return $this->firstDomain ?? $this->siteId;
    }
}
