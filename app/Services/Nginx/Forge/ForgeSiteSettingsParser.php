<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

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

        if ($gated !== null) {
            return new ForgeSiteSettings(
                analyticsEnabled: true,
                trackingTag: $gated['tag'],
                scriptBody: $gated['body'],
                conditionalAccessLog: $this->hasConditionalAccessLog($content),
                accessLogPath: $this->extractAccessLogPath($content) ?? '',
                gateNotBot: $gated['gates']['gateNotBot'] ?? false,
                gateHasFbclid: $gated['gates']['gateHasFbclid'] ?? false,
                gateIsTargetCountry: $gated['gates']['gateIsTargetCountry'] ?? false,
                gateIsTargetPage: $gated['gates']['gateIsTargetPage'] ?? false,
                targetCountries: $targetCountries,
                targetPages: $targetPages,
            );
        }

        return new ForgeSiteSettings(
            analyticsEnabled: $this->hasAnalytics($content),
            trackingTag: $this->extractTrackingTag($content) ?? '</head>',
            scriptBody: $this->extractScriptBody($content) ?? '',
            conditionalAccessLog: $this->hasConditionalAccessLog($content),
            accessLogPath: $this->extractAccessLogPath($content) ?? '',
            targetCountries: $targetCountries,
            targetPages: $targetPages,
        );
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

    private function hasConditionalAccessLog(string $content): bool
    {
        return preg_match('/access_log\s+\S.*if=\$site_[0-9]+_log_fbclid\s*;/', $content) === 1;
    }

    private function extractAccessLogPath(string $content): ?string
    {
        if (preg_match('/access_log\s+(\'((?:\\\\.|[^\'\\\\])*)\'|(\S+))\s+combined\s+if=\$site_[0-9]+_log_fbclid\s*;/', $content, $m) !== 1) {
            return null;
        }

        if (($m[2] ?? '') !== '') {
            return $this->unescapeSingleQuoted($m[2]);
        }

        return $m[3] ?? '';
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
}
