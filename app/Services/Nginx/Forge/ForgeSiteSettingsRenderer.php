<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;

class ForgeSiteSettingsRenderer
{
    private const TAG_WHITELIST = ['</head>', '</body>', '<head>', '<body>'];

    public function render(string $siteId, ForgeSiteSettings $settings): string
    {
        if ($settings->isEmpty()) {
            return '';
        }

        $parts = [
            '# Managed by redteam-manager. Do not edit by hand.',
            "# Site: {$siteId}",
            '',
        ];

        if ($settings->conditionalAccessLog && $settings->accessLogPath !== '') {
            $parts[] = '# --- conditional access log ---';
            $parts[] = 'map $arg_fbclid $log_specific_fbclid {';
            $parts[] = '    default 0;';
            $parts[] = '    ~.+     1;';
            $parts[] = '}';
            $parts[] = sprintf(
                'access_log %s combined if=$log_specific_fbclid;',
                $this->escapeSingleQuoted($settings->accessLogPath),
            );
            $parts[] = '';
        }

        if ($settings->analyticsEnabled && $settings->scriptBody !== '') {
            $tag = in_array($settings->trackingTag, self::TAG_WHITELIST, true)
                ? $settings->trackingTag
                : '</head>';

            $escapedTag = $this->escapeSingleQuoted($tag);
            $escapedReplacement = $this->escapeSingleQuoted($settings->scriptBody).$escapedTag;

            if ($settings->restrictToTargetPages) {
                $var = "site_{$siteId}_analytics_script";

                $parts[] = '# --- analytics injection (gated on $is_target_page) ---';
                $parts[] = sprintf('map $is_target_page $%s {', $var);
                $parts[] = sprintf("    default '%s';", $escapedTag);
                $parts[] = sprintf("    1       '%s';", $escapedReplacement);
                $parts[] = '}';
                $parts[] = 'sub_filter_once on;';
                $parts[] = sprintf("sub_filter '%s' \$%s;", $escapedTag, $var);
            } else {
                $parts[] = '# --- analytics injection ---';
                $parts[] = 'sub_filter_once on;';
                $parts[] = sprintf("sub_filter '%s' '%s';", $escapedTag, $escapedReplacement);
            }

            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
