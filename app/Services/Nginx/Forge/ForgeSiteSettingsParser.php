<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;

class ForgeSiteSettingsParser
{
    public function parse(string $content): ForgeSiteSettings
    {
        $gated = $this->extractGatedAnalytics($content);

        if ($gated !== null) {
            return new ForgeSiteSettings(
                analyticsEnabled: true,
                trackingTag: $gated['tag'],
                scriptBody: $gated['body'],
                conditionalAccessLog: $this->hasConditionalAccessLog($content),
                accessLogPath: $this->extractAccessLogPath($content) ?? '',
                restrictToTargetPages: true,
            );
        }

        return new ForgeSiteSettings(
            analyticsEnabled: $this->hasAnalytics($content),
            trackingTag: $this->extractTrackingTag($content) ?? '</head>',
            scriptBody: $this->extractScriptBody($content) ?? '',
            conditionalAccessLog: $this->hasConditionalAccessLog($content),
            accessLogPath: $this->extractAccessLogPath($content) ?? '',
            restrictToTargetPages: false,
        );
    }

    /**
     * @return array{tag: string, body: string}|null
     */
    private function extractGatedAnalytics(string $content): ?array
    {
        $pattern = '/map\s+\$is_target_page\s+\$site_[0-9]+_analytics_script\s*\{\s*default\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*1\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;\s*\}/';

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        $tag = $this->unescapeSingleQuoted($m[1]);
        $replacement = $this->unescapeSingleQuoted($m[2]);

        $body = str_ends_with($replacement, $tag)
            ? substr($replacement, 0, -strlen($tag))
            : $replacement;

        return ['tag' => $tag, 'body' => $body];
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

        if (str_ends_with($replacement, $tag)) {
            return substr($replacement, 0, -strlen($tag));
        }

        return $replacement;
    }

    private function hasConditionalAccessLog(string $content): bool
    {
        return str_contains($content, '$log_specific_fbclid')
            && preg_match('/access_log\s+\S.*if=\$log_specific_fbclid\s*;/', $content) === 1;
    }

    private function extractAccessLogPath(string $content): ?string
    {
        if (preg_match('/access_log\s+(\'((?:\\\\.|[^\'\\\\])*)\'|(\S+))\s+combined\s+if=\$log_specific_fbclid\s*;/', $content, $m) !== 1) {
            return null;
        }

        if (($m[2] ?? '') !== '') {
            return $this->unescapeSingleQuoted($m[2]);
        }

        return $m[3] ?? '';
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
