<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;

class ForgeSiteSettingsParser
{
    public function parse(string $content): ForgeSiteSettings
    {
        return new ForgeSiteSettings(
            analyticsEnabled: $this->hasAnalytics($content),
            trackingTag: $this->extractTrackingTag($content) ?? '</head>',
            scriptBody: $this->extractScriptBody($content) ?? '',
            conditionalAccessLog: $this->hasConditionalAccessLog($content),
            accessLogPath: $this->extractAccessLogPath($content) ?? '',
        );
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
        return '/sub_filter\s+\'((?:\\\\.|[^\'\\\\])*)\'\s+\'((?:\\\\.|[^\'\\\\])*)\'\s*;/';
    }

    private function unescapeSingleQuoted(string $value): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }
}
