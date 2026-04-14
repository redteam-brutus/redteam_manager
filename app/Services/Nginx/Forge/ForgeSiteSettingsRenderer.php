<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;

class ForgeSiteSettingsRenderer
{
    private const TAG_WHITELIST = ['</head>', '</body>', '<head>', '<body>'];

    /**
     * Fixed-order signal table. Gate field → nginx variable name + required value for the match row.
     *
     * @var list<array{field: string, var: string, value: string}>
     */
    private const SIGNAL_TABLE = [
        ['field' => 'gateNotBot',          'var' => 'is_bot',            'value' => '0'],
        ['field' => 'gateHasFbclid',       'var' => 'has_fbclid',        'value' => '1'],
        ['field' => 'gateIsTargetCountry', 'var' => 'is_target_country', 'value' => '1'],
        ['field' => 'gateIsTargetPage',    'var' => 'is_target_page',    'value' => '1'],
    ];

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
            $enabled = $this->enabledSignals($settings);

            if ($enabled === []) {
                $parts[] = '# --- analytics injection ---';
                $parts[] = 'sub_filter_once on;';
                $parts[] = sprintf("sub_filter '%s' '%s';", $escapedTag, $escapedReplacement);
            } else {
                if ($settings->gateHasFbclid) {
                    $parts[] = '# --- $has_fbclid helper ---';
                    $parts[] = 'map $arg_fbclid $has_fbclid {';
                    $parts[] = '    default 1;';
                    $parts[] = '    ""      0;';
                    $parts[] = '}';
                    $parts[] = '';
                }

                $var = "site_{$siteId}_analytics_script";
                $parts[] = '# --- analytics injection (gated) ---';

                if (count($enabled) === 1) {
                    $signal = $enabled[0];
                    $parts[] = sprintf('map $%s $%s {', $signal['var'], $var);
                    $parts[] = sprintf("    default '%s';", $escapedTag);
                    $parts[] = sprintf("    %s       '%s';", $signal['value'], $escapedReplacement);
                    $parts[] = '}';
                } else {
                    $keyVars = implode(':', array_map(fn (array $s): string => '$'.$s['var'], $enabled));
                    $keyValues = implode(':', array_map(fn (array $s): string => $s['value'], $enabled));

                    $parts[] = sprintf('map "%s" $%s {', $keyVars, $var);
                    $parts[] = sprintf("    default '%s';", $escapedTag);
                    $parts[] = sprintf("    \"%s\"      '%s';", $keyValues, $escapedReplacement);
                    $parts[] = '}';
                }

                $parts[] = 'sub_filter_once on;';
                $parts[] = sprintf("sub_filter '%s' \$%s;", $escapedTag, $var);
            }

            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<array{field: string, var: string, value: string}>
     */
    private function enabledSignals(ForgeSiteSettings $settings): array
    {
        $enabled = [];

        foreach (self::SIGNAL_TABLE as $signal) {
            if ($settings->{$signal['field']}) {
                $enabled[] = $signal;
            }
        }

        return $enabled;
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
