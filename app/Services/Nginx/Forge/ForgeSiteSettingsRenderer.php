<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\Dto\RenderedForgeSite;
use InvalidArgumentException;

class ForgeSiteSettingsRenderer
{
    private const TAG_WHITELIST = ['</head>', '</body>', '<head>', '<body>'];

    /**
     * Fixed-order signal table. Gate field → nginx variable name + required value + whether the
     * variable is global (defined by antibot) or site-scoped (defined by this site's http file).
     *
     * @var list<array{field: string, var: string, value: string, scope: string}>
     */
    private const SIGNAL_TABLE = [
        ['field' => 'gateNotBot',          'var' => 'is_bot',            'value' => '0', 'scope' => 'global'],
        ['field' => 'gateHasFbclid',       'var' => 'has_fbclid',        'value' => '1', 'scope' => 'site'],
        ['field' => 'gateIsTargetCountry', 'var' => 'is_target_country', 'value' => '1', 'scope' => 'site'],
        ['field' => 'gateIsTargetPage',    'var' => 'is_target_page',    'value' => '1', 'scope' => 'site'],
    ];

    public function render(string $siteId, ForgeSiteSettings $settings): RenderedForgeSite
    {
        if ($settings->isEmpty()) {
            return new RenderedForgeSite;
        }

        foreach ($settings->targetCountries as $code) {
            $this->assertCountryCode($code);
        }

        foreach ($settings->targetPages as $body) {
            $this->assertTargetPage($body);
        }

        $enabled = $this->enabledSignals($settings);
        $scriptVar = "site_{$siteId}_analytics_script";
        $logVar = "site_{$siteId}_log_fbclid";
        $hasFbclidVar = "site_{$siteId}_has_fbclid";
        $targetCountryVar = "site_{$siteId}_is_target_country";
        $targetPageVar = "site_{$siteId}_is_target_page";

        $httpParts = [
            '# Managed by redteam-manager. Do not edit by hand.',
            "# Site: {$siteId}",
            '# Context: http (included by /etc/nginx/nginx.conf via conf.d)',
            '',
        ];

        $serverParts = [
            '# Managed by redteam-manager. Do not edit by hand.',
            "# Site: {$siteId}",
            '# Context: server (included by forge-conf/<id>/site.conf)',
            '',
        ];

        $httpHasContent = false;
        $serverHasContent = false;

        if ($settings->conditionalAccessLog && $settings->accessLogPath !== '') {
            $httpParts[] = '# --- conditional access log helper ---';
            $httpParts[] = "map \$arg_fbclid \${$logVar} {";
            $httpParts[] = '    default 0;';
            $httpParts[] = '    ~.+     1;';
            $httpParts[] = '}';
            $httpParts[] = '';
            $httpHasContent = true;

            $serverParts[] = '# --- conditional access log ---';
            $serverParts[] = sprintf(
                'access_log %s combined if=$%s;',
                $this->escapeSingleQuoted($settings->accessLogPath),
                $logVar,
            );
            $serverParts[] = '';
            $serverHasContent = true;
        }

        if ($settings->analyticsEnabled && $settings->scriptBody !== '') {
            $tag = in_array($settings->trackingTag, self::TAG_WHITELIST, true)
                ? $settings->trackingTag
                : '</head>';

            $body = $this->ensureScriptWrap($settings->scriptBody);
            $escapedTag = $this->escapeSingleQuoted($tag);
            $escapedReplacement = $this->escapeSingleQuoted($body).$escapedTag;

            if ($enabled === []) {
                $serverParts[] = '# --- analytics injection ---';
                $serverParts[] = 'sub_filter_once on;';
                $serverParts[] = sprintf("sub_filter '%s' '%s';", $escapedTag, $escapedReplacement);
                $serverParts[] = '';
                $serverHasContent = true;
            } else {
                if ($settings->gateHasFbclid) {
                    $httpParts[] = "# --- \${$hasFbclidVar} helper ---";
                    $httpParts[] = "map \$arg_fbclid \${$hasFbclidVar} {";
                    $httpParts[] = '    default 1;';
                    $httpParts[] = '    ""      0;';
                    $httpParts[] = '}';
                    $httpParts[] = '';
                    $httpHasContent = true;
                }

                if ($settings->gateIsTargetCountry) {
                    $httpParts[] = "# --- \${$targetCountryVar} (per-site) ---";
                    $httpParts[] = "map \$http_cf_ipcountry \${$targetCountryVar} {";
                    $httpParts[] = '    default 0;';

                    foreach ($settings->targetCountries as $code) {
                        $httpParts[] = sprintf('    "%s" 1;', $code);
                    }

                    $httpParts[] = '}';
                    $httpParts[] = '';
                    $httpHasContent = true;
                }

                if ($settings->gateIsTargetPage) {
                    $httpParts[] = "# --- \${$targetPageVar} (per-site) ---";
                    $httpParts[] = "map \$request_uri \${$targetPageVar} {";
                    $httpParts[] = '    default 0;';

                    foreach ($settings->targetPages as $body) {
                        $httpParts[] = sprintf('    "~*%s" 1;', $body);
                    }

                    $httpParts[] = '}';
                    $httpParts[] = '';
                    $httpHasContent = true;
                }

                $httpParts[] = '# --- analytics injection (gated) ---';

                if (count($enabled) === 1) {
                    $signal = $enabled[0];
                    $signalVar = $this->resolveSignalVar($signal, $siteId);
                    $httpParts[] = sprintf('map $%s $%s {', $signalVar, $scriptVar);
                    $httpParts[] = sprintf("    default '%s';", $escapedTag);
                    $httpParts[] = sprintf("    %s       '%s';", $signal['value'], $escapedReplacement);
                    $httpParts[] = '}';
                } else {
                    $keyVars = implode(':', array_map(
                        fn (array $s): string => '$'.$this->resolveSignalVar($s, $siteId),
                        $enabled,
                    ));
                    $keyValues = implode(':', array_map(fn (array $s): string => $s['value'], $enabled));

                    $httpParts[] = sprintf('map "%s" $%s {', $keyVars, $scriptVar);
                    $httpParts[] = sprintf("    default '%s';", $escapedTag);
                    $httpParts[] = sprintf("    \"%s\"      '%s';", $keyValues, $escapedReplacement);
                    $httpParts[] = '}';
                }

                $httpParts[] = '';
                $httpHasContent = true;

                $serverParts[] = '# --- analytics injection (gated) ---';
                $serverParts[] = 'sub_filter_once on;';
                $serverParts[] = sprintf("sub_filter '%s' \$%s;", $escapedTag, $scriptVar);
                $serverParts[] = '';
                $serverHasContent = true;
            }
        }

        return new RenderedForgeSite(
            httpContext: $httpHasContent ? implode("\n", $httpParts) : '',
            serverContext: $serverHasContent ? implode("\n", $serverParts) : '',
        );
    }

    /**
     * @return list<array{field: string, var: string, value: string, scope: string}>
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

    /**
     * @param  array{field: string, var: string, value: string, scope: string}  $signal
     */
    private function resolveSignalVar(array $signal, string $siteId): string
    {
        return $signal['scope'] === 'site'
            ? "site_{$siteId}_{$signal['var']}"
            : $signal['var'];
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Wrap the script body in <script>…</script> when the user didn't provide
     * their own <script> tag. Leaves custom wrappers (e.g. `<script src="…">`,
     * `<script async>`, or multiple tags) untouched.
     */
    private function ensureScriptWrap(string $body): string
    {
        if (stripos($body, '<script') !== false) {
            return $body;
        }

        return '<script>'.$body.'</script>';
    }

    private function assertCountryCode(string $code): void
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new InvalidArgumentException("Country code must be 2 uppercase letters: {$code}");
        }
    }

    private function assertTargetPage(string $body): void
    {
        if ($body === '') {
            throw new InvalidArgumentException('Target page pattern cannot be empty.');
        }

        if (preg_match('/^(?:\\\\.|[^"\n\r])+$/D', $body) !== 1) {
            throw new InvalidArgumentException("Target page body contains unescaped quote or newline: {$body}");
        }
    }
}
