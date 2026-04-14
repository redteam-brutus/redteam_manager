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

            $parts[] = '# --- analytics injection ---';
            $parts[] = 'sub_filter_once on;';
            $parts[] = sprintf(
                "sub_filter '%s' '%s%s';",
                $this->escapeSingleQuoted($tag),
                $this->escapeSingleQuoted($settings->scriptBody),
                $this->escapeSingleQuoted($tag),
            );
            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
