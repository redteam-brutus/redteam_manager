<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteCustomLog;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;

class ForgeSiteSettingsParser
{
    /**
     * Signal table inverse of ForgeSiteSettingsRenderer::SIGNAL_TABLE. Captured variables are
     * first normalized via stripSitePrefix() so site-scoped helpers (e.g. $site_123_has_fbclid)
     * resolve to the underlying signal name used as the lookup key here.
     *
     * @var array<string, array{field: string, value: string}>
     */
    private const SIGNAL_LOOKUP = [
        'is_bot' => ['field' => 'gateNotBot', 'value' => '0'],
        'has_fbclid' => ['field' => 'gateHasFbclid', 'value' => '1'],
        'is_target_country' => ['field' => 'gateIsTargetCountry', 'value' => '1'],
        'is_target_page' => ['field' => 'gateIsTargetPage', 'value' => '1'],
    ];

    public function parse(string $content): ForgeSiteSettings
    {
        $gated = $this->extractGatedAnalytics($content);
        $targetCountries = $this->extractTargetCountries($content);
        $targetPages = $this->extractTargetPages($content);
        $socialRefererHosts = $this->extractSocialRefererHosts($content);
        $customLogs = $this->extractCustomLogs($content);

        if ($gated !== null) {
            return new ForgeSiteSettings(
                analyticsEnabled: true,
                trackingTag: $gated['tag'],
                scriptBody: $gated['body'],
                siteLoggingEnabled: $this->hasSiteLogging($content),
                gateNotBot: $gated['gates']['gateNotBot'] ?? false,
                gateHasFbclid: $gated['gates']['gateHasFbclid'] ?? false,
                gateIsTargetCountry: $gated['gates']['gateIsTargetCountry'] ?? false,
                gateIsTargetPage: $gated['gates']['gateIsTargetPage'] ?? false,
                targetCountries: $targetCountries,
                targetPages: $targetPages,
                socialRefererHosts: $socialRefererHosts,
                customLogs: $customLogs,
            );
        }

        $logGates = $this->extractSiteLogGates($content);

        return new ForgeSiteSettings(
            analyticsEnabled: $this->hasAnalytics($content),
            trackingTag: $this->extractTrackingTag($content) ?? '</head>',
            scriptBody: $this->extractScriptBody($content) ?? '',
            siteLoggingEnabled: $this->hasSiteLogging($content),
            gateNotBot: $logGates['gateNotBot'] ?? false,
            gateHasFbclid: $logGates['gateHasFbclid'] ?? false,
            gateIsTargetCountry: $logGates['gateIsTargetCountry'] ?? false,
            gateIsTargetPage: $logGates['gateIsTargetPage'] ?? false,
            targetCountries: $targetCountries,
            targetPages: $targetPages,
            socialRefererHosts: $socialRefererHosts,
            customLogs: $customLogs,
        );
    }

    /**
     * Extract site-level gate flags from the `$site_<id>_gate_hit` map. Used when
     * analytics is disabled but `siteLoggingEnabled` is true with gates set — those
     * flags drive gate.log emission via this map (renderer lines ~252-271).
     *
     * @return array<string, bool>
     */
    private function extractSiteLogGates(string $content): array
    {
        $composite = '/map\s+"((?:\$\w+)(?::\$\w+)+)"\s+\$site_[0-9]+_gate_hit\s*\{\s*default\s+0\s*;\s*"([^"]+)"\s+1\s*;\s*\}/';

        if (preg_match($composite, $content, $m) === 1) {
            $varList = array_map(fn (string $v): string => ltrim($v, '$'), explode(':', $m[1]));
            $valueList = explode(':', $m[2]);

            if (count($varList) === count($valueList)) {
                return $this->gatesFromSignals($varList, $valueList);
            }
        }

        $single = '/map\s+\$(\w+)\s+\$site_[0-9]+_gate_hit\s*\{\s*default\s+0\s*;\s*(\S+)\s+1\s*;\s*\}/';

        if (preg_match($single, $content, $m) === 1) {
            return $this->gatesFromSignals([$m[1]], [$m[2]]);
        }

        return [];
    }

    /**
     * @return array{tag: string, body: string, gates: array<string, bool>}|null
     */
    private function extractGatedAnalytics(string $content): ?array
    {
        if (($match = $this->matchCompositeGate($content)) !== null) {
            return $match;
        }

        return $this->matchSingleSignalGate($content);
    }

    /**
     * @return array{tag: string, body: string, gates: array<string, bool>}|null
     */
    private function matchCompositeGate(string $content): ?array
    {
        $pattern = '/map\s+"((?:\$\w+)(?::\$\w+)+)"\s+\$site_[0-9]+_analytics_script\s*\{\s*default\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*"([^"]+)"\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*\}/';

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        $varList = array_map(fn (string $v): string => ltrim($v, '$'), explode(':', $m[1]));
        $valueList = explode(':', $m[3]);

        if (count($varList) !== count($valueList)) {
            return null;
        }

        $gates = $this->gatesFromSignals($varList, $valueList);
        $tag = $this->unescapeSingleQuoted($m[2]);
        $replacement = $this->unescapeSingleQuoted($m[4]);

        return [
            'tag' => $tag,
            'body' => $this->stripTrailingTag($replacement, $tag),
            'gates' => $gates,
        ];
    }

    /**
     * @return array{tag: string, body: string, gates: array<string, bool>}|null
     */
    private function matchSingleSignalGate(string $content): ?array
    {
        $pattern = '/map\s+\$(\w+)\s+\$site_[0-9]+_analytics_script\s*\{\s*default\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*(\S+)\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*\}/';

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        $gates = $this->gatesFromSignals([$m[1]], [$m[3]]);
        $tag = $this->unescapeSingleQuoted($m[2]);
        $replacement = $this->unescapeSingleQuoted($m[4]);

        return [
            'tag' => $tag,
            'body' => $this->stripTrailingTag($replacement, $tag),
            'gates' => $gates,
        ];
    }

    /**
     * @param  list<string>  $varList
     * @param  list<string>  $valueList
     * @return array<string, bool>
     */
    private function gatesFromSignals(array $varList, array $valueList): array
    {
        $gates = [];

        foreach ($varList as $index => $var) {
            $normalized = $this->stripSitePrefix($var);
            $lookup = self::SIGNAL_LOOKUP[$normalized] ?? null;

            if ($lookup === null) {
                continue;
            }

            if (($valueList[$index] ?? null) !== $lookup['value']) {
                continue;
            }

            $gates[$lookup['field']] = true;
        }

        return $gates;
    }

    private function stripSitePrefix(string $var): string
    {
        return preg_replace('/^site_[0-9]+_/', '', $var) ?? $var;
    }

    private function stripTrailingTag(string $replacement, string $tag): string
    {
        return str_ends_with($replacement, $tag)
            ? substr($replacement, 0, -strlen($tag))
            : $replacement;
    }

    private function hasAnalytics(string $content): bool
    {
        return str_contains($content, 'sub_filter_once on;')
            && preg_match($this->subFilterPattern(), $content) === 1;
    }

    private function extractTrackingTag(string $content): ?string
    {
        if (preg_match($this->subFilterPattern(), $content, $m) !== 1) {
            return null;
        }

        return $this->unescapeSingleQuoted($m[1]);
    }

    private function extractScriptBody(string $content): ?string
    {
        if (preg_match($this->subFilterPattern(), $content, $m) !== 1) {
            return null;
        }

        $tag = $this->unescapeSingleQuoted($m[1]);
        $replacement = $this->unescapeSingleQuoted($m[2]);

        return $this->stripTrailingTag($replacement, $tag);
    }

    private function hasSiteLogging(string $content): bool
    {
        return preg_match('/log_format\s+site_[0-9]+_verbose\b/', $content) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractTargetCountries(string $content): array
    {
        $body = $this->extractSiteMapBody($content, '$http_cf_ipcountry', 'is_target_country');

        if ($body === null || preg_match_all('/"([A-Z]{2})"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return $m[1] ?? [];
    }

    /**
     * @return list<string>
     */
    private function extractTargetPages(string $content): array
    {
        $body = $this->extractSiteMapBody($content, '$request_uri', 'is_target_page');

        if ($body === null || preg_match_all('/"~\*((?:\\\\.|[^"\\\\])*)"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return $m[1] ?? [];
    }

    /**
     * @return list<string>
     */
    private function extractSocialRefererHosts(string $content): array
    {
        $body = $this->extractSiteMapBody($content, '$http_referer', 'has_social_referer');

        if ($body === null) {
            return [];
        }

        if (preg_match_all('/"~\*\^https\?:\/\/\(\[\^\/\]\*\\\\\.\)\?(.+?)\(\/\|\$\)"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return array_map(
            fn (string $host): string => str_replace('\\.', '.', $host),
            $m[1] ?? [],
        );
    }

    private function extractSiteMapBody(string $content, string $source, string $suffix): ?string
    {
        $pattern = sprintf(
            '/map\s+%s\s+\$site_[0-9]+_%s\s*\{([^}]*)\}/',
            preg_quote($source, '/'),
            preg_quote($suffix, '/'),
        );

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function subFilterPattern(): string
    {
        // Only matches the non-gated form: `sub_filter 'tag' 'replacement';`.
        // The gated form uses a variable (no quote after the tag), which this pattern deliberately won't match.
        return '/sub_filter\s+\'((?:\\\\.|[^\'\\\\])*)\'\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;/';
    }

    private function unescapeSingleQuoted(string $value): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }

    /**
     * @return list<ForgeSiteCustomLog>
     */
    private function extractCustomLogs(string $content): array
    {
        $pattern = '/access_log\s+\/var\/log\/nginx\/site-(\d+)-([a-z][a-z0-9_]*)\.log\s+site_(\d+)_verbose\s+if=\$site_(\d+)_log_([a-z][a-z0-9_]*)_hit\s*;/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $logs = [];
        $seen = [];

        foreach ($matches as $m) {
            $siteId = $m[1];
            $slug = $m[2];

            // Reject malformed configs where site IDs disagree across the access_log path,
            // log_format name, and hit-var (defense against hand-edited or corrupted files).
            if ($m[3] !== $siteId || $m[4] !== $siteId) {
                continue;
            }

            if ($m[5] !== $slug) {
                continue;
            }

            if (in_array($slug, ForgeSiteCustomLog::RESERVED_SLUGS, true)) {
                continue;
            }

            if (isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $logs[] = $this->buildCustomLog($content, $siteId, $slug);
        }

        return $logs;
    }

    private function buildCustomLog(string $content, string $siteId, string $slug): ForgeSiteCustomLog
    {
        $hitVar = "site_{$siteId}_log_{$slug}_hit";
        [$varList, $valueList] = $this->extractHitMapSignals($content, $hitVar);

        $requireNotBot = false;
        $requireFbclid = false;
        $requireSocialReferer = false;
        $requireTargetCountry = false;
        $requireTargetPage = false;

        foreach ($varList as $i => $var) {
            $value = $valueList[$i] ?? null;
            $tail = $this->classifyLogVar($var, $siteId, $slug);

            if ($tail === null) {
                continue;
            }

            switch ($tail) {
                case 'is_bot':
                    if ($value === '0') {
                        $requireNotBot = true;
                    }
                    break;
                case 'has_fbclid_arg':
                    if ($value === '1') {
                        $requireFbclid = true;
                    }
                    break;
                case 'has_social_referer':
                case 'log:has_social_referer':
                    if ($value === '1') {
                        $requireSocialReferer = true;
                    }
                    break;
                case 'has_fbclid':
                case 'log:has_fbclid':
                    if ($value === '1') {
                        $requireFbclid = true;
                        $requireSocialReferer = true;
                    }
                    break;
                case 'is_target_country':
                case 'log:is_target_country':
                    if ($value === '1') {
                        $requireTargetCountry = true;
                    }
                    break;
                case 'is_target_page':
                case 'log:is_target_page':
                    if ($value === '1') {
                        $requireTargetPage = true;
                    }
                    break;
            }
        }

        $overrideCountries = $this->extractLogOverrideMap(
            $content,
            $siteId,
            $slug,
            'is_target_country',
            '$http_cf_ipcountry',
        );

        if ($overrideCountries !== null) {
            $overrideCountries = $this->extractCountryEntries($overrideCountries);
        }

        $overridePages = $this->extractLogOverrideMap(
            $content,
            $siteId,
            $slug,
            'is_target_page',
            '$request_uri',
        );

        if ($overridePages !== null) {
            $overridePages = $this->extractPageEntries($overridePages);
        }

        $overrideHosts = $this->extractLogOverrideMap(
            $content,
            $siteId,
            $slug,
            'has_social_referer',
            '$http_referer',
        );

        if ($overrideHosts !== null) {
            $overrideHosts = $this->extractRefererHostEntries($overrideHosts);
        }

        return new ForgeSiteCustomLog(
            slug: $slug,
            label: $slug,
            requireNotBot: $requireNotBot,
            requireFbclid: $requireFbclid,
            requireSocialReferer: $requireSocialReferer,
            requireTargetCountry: $requireTargetCountry,
            requireTargetPage: $requireTargetPage,
            overrideCountries: $overrideCountries,
            overridePages: $overridePages,
            overrideSocialRefererHosts: $overrideHosts,
        );
    }

    /**
     * Resolve a (raw, no leading $) variable name relative to a custom log's site + slug.
     *
     * Returns:
     *   - "log:<suffix>" — references the per-log override map for that suffix (e.g. log:is_target_country)
     *   - "<suffix>"     — references the site-level helper (e.g. has_fbclid_arg, is_target_country)
     *   - "<name>"       — a global var (e.g. is_bot)
     */
    private function classifyLogVar(string $var, string $siteId, string $slug): ?string
    {
        $name = ltrim($var, '$');

        $logPrefix = "site_{$siteId}_log_{$slug}_";

        if (str_starts_with($name, $logPrefix)) {
            return 'log:'.substr($name, strlen($logPrefix));
        }

        $sitePrefix = "site_{$siteId}_";

        if (str_starts_with($name, $sitePrefix)) {
            return substr($name, strlen($sitePrefix));
        }

        return $name;
    }

    /**
     * Locate a hit map by name and return its referenced var list + matching value list.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function extractHitMapSignals(string $content, string $hitVar): array
    {
        $hitVarQuoted = preg_quote($hitVar, '/');

        // Composite: map "$a:$b:..." $hit { default 0; "1:1:..." 1; }
        $compositePattern = '/map\s+"((?:\$\w+)(?::\$\w+)+)"\s+\$'.$hitVarQuoted.'\s*\{\s*default\s+0\s*;\s*"([^"]+)"\s+1\s*;\s*\}/';

        if (preg_match($compositePattern, $content, $m) === 1) {
            $vars = explode(':', $m[1]);
            $values = explode(':', $m[2]);

            return [$vars, $values];
        }

        // Single: map $a $hit { default 0; "1" 1; }
        $singlePattern = '/map\s+\$(\w+)\s+\$'.$hitVarQuoted.'\s*\{\s*default\s+0\s*;\s*"([^"]+)"\s+1\s*;\s*\}/';

        if (preg_match($singlePattern, $content, $m) === 1) {
            return [['$'.$m[1]], [$m[2]]];
        }

        // Always-on: map $request_id $hit { default 1; }
        $alwaysPattern = '/map\s+\$request_id\s+\$'.$hitVarQuoted.'\s*\{\s*default\s+1\s*;\s*\}/';

        if (preg_match($alwaysPattern, $content) === 1) {
            return [[], []];
        }

        return [[], []];
    }

    /**
     * Find a per-log override map body (the raw content between `{` and `}`). Returns null when the
     * map is absent (i.e. the log inherits the site-level list).
     */
    private function extractLogOverrideMap(
        string $content,
        string $siteId,
        string $slug,
        string $suffix,
        string $source,
    ): ?string {
        $varName = "site_{$siteId}_log_{$slug}_{$suffix}";

        $pattern = sprintf(
            '/map\s+%s\s+\$%s\s*\{([^}]*)\}/',
            preg_quote($source, '/'),
            preg_quote($varName, '/'),
        );

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @return list<string>
     */
    private function extractCountryEntries(string $body): array
    {
        if (preg_match_all('/"([A-Z]{2})"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return $m[1] ?? [];
    }

    /**
     * @return list<string>
     */
    private function extractPageEntries(string $body): array
    {
        if (preg_match_all('/"~\*((?:\\\\.|[^"\\\\])*)"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return $m[1] ?? [];
    }

    /**
     * @return list<string>
     */
    private function extractRefererHostEntries(string $body): array
    {
        if (preg_match_all('/"~\*\^https\?:\/\/\(\[\^\/\]\*\\\\\.\)\?(.+?)\(\/\|\$\)"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return array_map(
            fn (string $host): string => str_replace('\\.', '.', $host),
            $m[1] ?? [],
        );
    }
}
