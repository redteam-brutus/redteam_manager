<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Dto;

final readonly class SiteTrafficSnapshot
{
    /**
     * @param  list<SiteTrafficRow>  $rows
     */
    public function __construct(
        public int $totalVisits,
        public int $totalGateHits,
        public int $totalFbclidHits,
        public int $activeInjectionSites,
        public array $rows,
        public \DateTimeImmutable $generatedAt,
    ) {}
}
