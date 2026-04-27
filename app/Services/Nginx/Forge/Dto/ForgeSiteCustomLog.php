<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge\Dto;

use InvalidArgumentException;

/**
 * Single user-defined log stream for a site. Maps to one access_log line in the rendered nginx
 * config (`/var/log/nginx/site-{id}-{slug}.log`) gated by an `if=$site_{id}_log_{slug}_hit` map.
 *
 * Slugs are reserved against `access` and `gate` (the legacy paths) and validated against
 * /^[a-z][a-z0-9_]{0,31}$/. Override fields are nullable: null means "inherit site-level list".
 */
final readonly class ForgeSiteCustomLog
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public const RESERVED_SLUGS = ['access', 'gate'];

    /**
     * @param  ?list<string>  $overrideCountries  null = inherit site-level targetCountries
     * @param  ?list<string>  $overridePages  null = inherit site-level targetPages
     * @param  ?list<string>  $overrideSocialRefererHosts  null = inherit site-level socialRefererHosts
     */
    public function __construct(
        public string $slug,
        public string $label = '',
        public bool $requireNotBot = false,
        public bool $requireFbclid = false,
        public bool $requireSocialReferer = false,
        public bool $requireTargetCountry = false,
        public bool $requireTargetPage = false,
        public ?array $overrideCountries = null,
        public ?array $overridePages = null,
        public ?array $overrideSocialRefererHosts = null,
    ) {
        self::assertSlug($slug);
    }

    public static function assertSlug(string $slug): void
    {
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new InvalidArgumentException("Custom log slug '{$slug}' is reserved.");
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                "Custom log slug must match ^[a-z][a-z0-9_]{0,31}$: '{$slug}'."
            );
        }
    }

    public function hasAnyRequirement(): bool
    {
        return $this->requireNotBot
            || $this->requireFbclid
            || $this->requireSocialReferer
            || $this->requireTargetCountry
            || $this->requireTargetPage;
    }

    /**
     * Whether the underlying composite `has_fbclid` map (arg OR referer) is required by this log.
     */
    public function requiresFbclidComposite(): bool
    {
        return $this->requireFbclid && $this->requireSocialReferer;
    }
}
