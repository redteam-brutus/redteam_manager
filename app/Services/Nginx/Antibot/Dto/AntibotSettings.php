<?php

declare(strict_types=1);

namespace App\Services\Nginx\Antibot\Dto;

final readonly class AntibotSettings
{
    /**
     * Known-bot UA fragments pre-filled into new servers on first visit.
     *
     * @var list<string>
     */
    public const DEFAULT_BOT_PATTERNS = [
        'googlebot', 'bingbot', 'yandex', 'baiduspider', 'slurp', 'duckduckbot',
        'sogou', 'exabot', 'facebot', 'facebookexternalhit', 'twitterbot', 'rogerbot',
        'linkedinbot', 'embedly', 'slackbot', 'discordbot', 'whatsapp', 'skypeuripreview',
        'telegrambot', 'quora', 'pinterest', 'vkShare', 'applebot', 'ahrefsbot',
        'semrushbot', 'mj12bot', 'dotbot', 'petalsbot', 'grapeshot', 'megaindex',
        'magpie-crawler', 'scrapy', 'curl', 'wget', 'python-requests', 'python-urllib',
        'libwww-perl', 'httpclient', 'java', 'ruby', 'postmanruntime', 'bot', 'spider',
        'crawler', 'scraper', 'crawling', 'archiver', 'archive.org_bot',
    ];

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
