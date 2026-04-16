<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Dto;

use DateTimeImmutable;

final readonly class RecentEdit
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
        public string $fileKind, // 'http' | 'server'
        public DateTimeImmutable $editedAt,
    ) {}
}
