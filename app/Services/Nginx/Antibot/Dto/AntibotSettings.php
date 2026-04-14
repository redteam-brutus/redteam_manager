<?php

declare(strict_types=1);

namespace App\Services\Nginx\Antibot\Dto;

final readonly class AntibotSettings
{
    /**
     * @param  list<string>  $botPatterns  Regex alternatives inserted into the ~*(...) group, e.g. ['googlebot', 'bingbot'].
     * @param  list<string>  $targetCountries  2-letter uppercase CF country codes, e.g. ['IL', 'EG'].
     * @param  list<string>  $targetPages  URI regex bodies (without the ~* prefix), e.g. ['^/page-1/'].
     */
    public function __construct(
        public array $botPatterns = [],
        public array $targetCountries = [],
        public array $targetPages = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->botPatterns === []
            && $this->targetCountries === []
            && $this->targetPages === [];
    }
}
