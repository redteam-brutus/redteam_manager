<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Services\Nginx\Forge\Dto\ForgeSiteCustomLog;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\Dto\RenderedForgeSite;
use InvalidArgumentException;

class ForgeSiteSettingsRenderer
{
    private const TAG_WHITELIST = ['</head>', '</body>', '<head>', '<body>'];

    /**
     * Per-site FBCLID source variable. Resolved against $request_uri (immutable) so SPA-style
     * rewrites that clear $args (e.g. `rewrite ^.*$ /index.html?`) don't strip the captured value.
     * The verbose log_format and all fbclid helper maps reference this var instead of $arg_fbclid.
     */
    private const FBCLID_VALUE_VAR = 'fbclid_value';

    private const VERBOSE_LOG_FORMAT = '[$time_local] Host: $host | IP: $remote_addr | ReqID: $request_id | Path: $uri | Request URI: $request_uri | FBCLID: $%s | UA: "$http_user_agent" | ISO: "$http_cf_ipcountry" | Prefetch: [$http_sec_fetch_dest] | Turbolink: [$http_x_requested_with] | client hints: [$http_sec_ch_ua] -  [$http_sec_ch_ua_platform] -  [$http_sec_ch_ua_mobile]';

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

    public static function defaultSiteAccessLogPath(string $siteId): string
    {
        return "/var/log/nginx/site-{$siteId}-access.log";
    }

    public static function defaultSiteGateLogPath(string $siteId): string
    {
        return "/var/log/nginx/site-{$siteId}-gate.log";
    }

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

        foreach ($settings->socialRefererHosts as $host) {
            $this->assertSocialRefererHost($host);
        }

        foreach ($settings->customLogs as $log) {
            $this->assertCustomLog($log);
        }

        $enabled = $this->enabledSignals($settings);
        $union = $this->signalUnion($settings);
        $scriptVar = "site_{$siteId}_analytics_script";
        $gateHitVar = "site_{$siteId}_gate_hit";
        $logFormatName = "site_{$siteId}_verbose";
        $hasFbclidVar = "site_{$siteId}_has_fbclid";
        $targetCountryVar = "site_{$siteId}_is_target_country";
        $targetPageVar = "site_{$siteId}_is_target_page";
        $fbclidValueVar = "site_{$siteId}_".self::FBCLID_VALUE_VAR;
        $fbclidNeedsHelper = $union['has_fbclid_arg'] || $union['has_fbclid_simple'] || $union['has_fbclid_composite'];

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

        // FBCLID source map. Extracts the fbclid query value from $request_uri (the original request
        // line, immutable across rewrites) instead of $arg_fbclid (parsed from $args, which SPA-style
        // rewrites like `rewrite ^.*$ /index.html?` clear). Emitted whenever the verbose log_format
        // or any fbclid helper would otherwise reference $arg_fbclid.
        if ($settings->siteLoggingEnabled || $fbclidNeedsHelper) {
            $captureName = "site_{$siteId}_fbclid_capture";
            $httpParts[] = "# --- \${$fbclidValueVar} (extracted from \$request_uri) ---";
            $httpParts[] = "map \$request_uri \${$fbclidValueVar} {";
            $httpParts[] = '    default "";';
            $httpParts[] = sprintf('    "~[?&]fbclid=(?<%s>[^&]*)" $%s;', $captureName, $captureName);
            $httpParts[] = '}';
            $httpParts[] = '';
            $httpHasContent = true;
        }

        // Helper variable maps. Driven by the helper-union of (site-level gate flags) ∪ (any custom
        // log's require flags). Custom logs widen the set so a per-log condition can reference a
        // helper var even when no site-level gate is enabled. Each helper emits independently —
        // simple form ($site_{id}_has_fbclid from arg only) can coexist with the standalone arg
        // helper when one consumer needs the simple-form named var and another needs the arg-only var.
        if ($union['has_fbclid_composite']) {
            $hasFbclidArgVar = "site_{$siteId}_has_fbclid_arg";
            $hasSocialRefererVar = "site_{$siteId}_has_social_referer";

            $httpParts[] = "# --- \${$hasFbclidVar} = fbclid OR social referer ---";
            $httpParts[] = "map \${$fbclidValueVar} \${$hasFbclidArgVar} {";
            $httpParts[] = '    default 1;';
            $httpParts[] = '    ""      0;';
            $httpParts[] = '}';
            $httpParts[] = '';
            $httpParts[] = "map \$http_referer \${$hasSocialRefererVar} {";
            $httpParts[] = '    default 0;';

            foreach ($settings->socialRefererHosts as $host) {
                $httpParts[] = sprintf(
                    '    "~*^https?://([^/]*\.)?%s(/|$)" 1;',
                    preg_quote($host, '/'),
                );
            }

            $httpParts[] = '}';
            $httpParts[] = '';
            $httpParts[] = sprintf(
                'map "$%s$%s" $%s {',
                $hasFbclidArgVar,
                $hasSocialRefererVar,
                $hasFbclidVar,
            );
            $httpParts[] = '    default 1;';
            $httpParts[] = '    "00"    0;';
            $httpParts[] = '}';
            $httpParts[] = '';
            $httpHasContent = true;
        } else {
            if ($union['has_fbclid_simple']) {
                $httpParts[] = "# --- \${$hasFbclidVar} helper ---";
                $httpParts[] = "map \${$fbclidValueVar} \${$hasFbclidVar} {";
                $httpParts[] = '    default 1;';
                $httpParts[] = '    ""      0;';
                $httpParts[] = '}';
                $httpParts[] = '';
                $httpHasContent = true;
            }

            if ($union['has_fbclid_arg']) {
                $hasFbclidArgVar = "site_{$siteId}_has_fbclid_arg";
                $httpParts[] = "# --- \${$hasFbclidArgVar} helper ---";
                $httpParts[] = "map \${$fbclidValueVar} \${$hasFbclidArgVar} {";
                $httpParts[] = '    default 1;';
                $httpParts[] = '    ""      0;';
                $httpParts[] = '}';
                $httpParts[] = '';
                $httpHasContent = true;
            }

            if ($union['has_social_referer']) {
                $hasSocialRefererVar = "site_{$siteId}_has_social_referer";
                $httpParts[] = "# --- \${$hasSocialRefererVar} helper ---";
                $httpParts[] = "map \$http_referer \${$hasSocialRefererVar} {";
                $httpParts[] = '    default 0;';

                foreach ($settings->socialRefererHosts as $host) {
                    $httpParts[] = sprintf(
                        '    "~*^https?://([^/]*\.)?%s(/|$)" 1;',
                        preg_quote($host, '/'),
                    );
                }

                $httpParts[] = '}';
                $httpParts[] = '';
                $httpHasContent = true;
            }
        }

        if ($union['is_target_country']) {
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

        if ($union['is_target_page']) {
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

        // Analytics injection.
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
                $httpParts[] = '# --- analytics injection (gated) ---';

                if (count($enabled) === 1) {
                    $signal = $enabled[0];
                    $signalVar = $this->resolveSignalVar($signal, $siteId);
                    $httpParts[] = sprintf('map $%s $%s {', $signalVar, $scriptVar);
                    $httpParts[] = sprintf("    default '%s';", $escapedTag);
                    $httpParts[] = sprintf("    %s       '%s';", $signal['value'], $escapedReplacement);
                    $httpParts[] = '}';
                } else {
                    [$keyVars, $keyValues] = $this->compositeKey($enabled, $siteId);
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

        // Site traffic logs — always-on access log + gate-matched log.
        if ($settings->siteLoggingEnabled) {
            $httpParts[] = "# --- verbose log format for site {$siteId} ---";
            $httpParts[] = sprintf(
                "log_format %s escape=none '%s';",
                $logFormatName,
                $this->escapeSingleQuoted(sprintf(self::VERBOSE_LOG_FORMAT, $fbclidValueVar)),
            );
            $httpParts[] = '';
            $httpHasContent = true;

            if ($enabled !== []) {
                $httpParts[] = "# --- \${$gateHitVar} (all enabled gates matched) ---";

                if (count($enabled) === 1) {
                    $signal = $enabled[0];
                    $signalVar = $this->resolveSignalVar($signal, $siteId);
                    $httpParts[] = sprintf('map $%s $%s {', $signalVar, $gateHitVar);
                    $httpParts[] = '    default 0;';
                    $httpParts[] = sprintf('    %s       1;', $signal['value']);
                    $httpParts[] = '}';
                } else {
                    [$keyVars, $keyValues] = $this->compositeKey($enabled, $siteId);
                    $httpParts[] = sprintf('map "%s" $%s {', $keyVars, $gateHitVar);
                    $httpParts[] = '    default 0;';
                    $httpParts[] = sprintf('    "%s"      1;', $keyValues);
                    $httpParts[] = '}';
                }

                $httpParts[] = '';
            }

            $serverParts[] = '# --- site traffic logs ---';
            $serverParts[] = sprintf(
                'access_log %s %s;',
                self::defaultSiteAccessLogPath($siteId),
                $logFormatName,
            );

            if ($enabled !== []) {
                $serverParts[] = sprintf(
                    'access_log %s %s if=$%s;',
                    self::defaultSiteGateLogPath($siteId),
                    $logFormatName,
                    $gateHitVar,
                );
            }

            // Per-custom-log override maps + hit map + access_log line.
            foreach ($settings->customLogs as $log) {
                [$logHttp, $logServer] = $this->renderCustomLog($log, $siteId, $logFormatName);

                foreach ($logHttp as $line) {
                    $httpParts[] = $line;
                }

                foreach ($logServer as $line) {
                    $serverParts[] = $line;
                }
            }

            $serverParts[] = '';
            $serverHasContent = true;
        }

        if ($httpHasContent) {
            // Per-site + per-custom-log helper maps create many nginx variables; the default
            // variables_hash_bucket_size of 64 is too small once a few custom logs are defined
            // and emerg "could not build variables_hash" at nginx -t. Bump it here in the http
            // context so the managed file is self-contained.
            array_splice($httpParts, 4, 0, [
                'variables_hash_bucket_size 256;',
                'variables_hash_max_size 2048;',
                '',
            ]);
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
     * Helper-union of (site-level gate flags) ∪ (any custom log's require flags).
     *
     * Drives which underlying $site_{id}_<signal> helper maps are emitted in the http context.
     * Note: site-level analytics-script gate uses strictly enabledSignals() — it is NOT widened by
     * custom logs. Only the helper map emission is widened.
     *
     * @return array{has_fbclid_arg: bool, has_social_referer: bool, has_fbclid_simple: bool, has_fbclid_composite: bool, is_target_country: bool, is_target_page: bool}
     */
    private function signalUnion(ForgeSiteSettings $settings): array
    {
        $union = [
            'has_fbclid_arg' => false,
            'has_social_referer' => false,
            'has_fbclid_simple' => false,
            'has_fbclid_composite' => false,
            'is_target_country' => false,
            'is_target_page' => false,
        ];

        // Site-level fbclid gate.
        if ($settings->gateHasFbclid) {
            if ($settings->socialRefererHosts === []) {
                $union['has_fbclid_simple'] = true;
            } else {
                $union['has_fbclid_composite'] = true;
            }
        }

        if ($settings->gateIsTargetCountry) {
            $union['is_target_country'] = true;
        }

        if ($settings->gateIsTargetPage) {
            $union['is_target_page'] = true;
        }

        // Custom logs widen the helper set. Override-mode logs build their own per-log maps and do
        // NOT require the corresponding site-level helper.
        foreach ($settings->customLogs as $log) {
            if ($log->requireFbclid && $log->requireSocialReferer) {
                if ($log->overrideSocialRefererHosts === null) {
                    $union['has_fbclid_composite'] = true;
                } else {
                    // Per-log composite reuses site-level $has_fbclid_arg + per-log referer map.
                    $union['has_fbclid_arg'] = true;
                }
            } elseif ($log->requireFbclid) {
                $union['has_fbclid_arg'] = true;
            } elseif ($log->requireSocialReferer && $log->overrideSocialRefererHosts === null) {
                $union['has_social_referer'] = true;
            }

            if ($log->requireTargetCountry && $log->overrideCountries === null) {
                $union['is_target_country'] = true;
            }

            if ($log->requireTargetPage && $log->overridePages === null) {
                $union['is_target_page'] = true;
            }
        }

        // Composite supersedes simple — composite emits all three maps including the named composite var.
        if ($union['has_fbclid_composite']) {
            $union['has_fbclid_simple'] = false;
            // Composite branch emits has_fbclid_arg internally, so suppress standalone emission.
            $union['has_fbclid_arg'] = false;
        }

        return $union;
    }

    private function assertCustomLog(ForgeSiteCustomLog $log): void
    {
        ForgeSiteCustomLog::assertSlug($log->slug);

        if ($log->overrideCountries !== null) {
            foreach ($log->overrideCountries as $code) {
                $this->assertCountryCode($code);
            }
        }

        if ($log->overridePages !== null) {
            foreach ($log->overridePages as $body) {
                $this->assertTargetPage($body);
            }
        }

        if ($log->overrideSocialRefererHosts !== null) {
            foreach ($log->overrideSocialRefererHosts as $host) {
                $this->assertSocialRefererHost($host);
            }
        }
    }

    /**
     * Resolve the list of nginx vars + expected values that must all match for a custom log to fire.
     * Per-log override maps are referenced when present, otherwise the site-level helper var.
     *
     * @return list<array{var: string, value: string}>
     */
    private function customLogResolvedSignals(ForgeSiteCustomLog $log, string $siteId): array
    {
        $signals = [];

        if ($log->requireNotBot) {
            $signals[] = ['var' => 'is_bot', 'value' => '0'];
        }

        if ($log->requireFbclid && $log->requireSocialReferer) {
            // Composite: arg OR referer. With per-log override hosts the composite must be rebuilt
            // against the per-log has_social_referer map; otherwise reuse the shared site-level
            // composite (always emitted via union).
            $var = $log->overrideSocialRefererHosts !== null
                ? "site_{$siteId}_log_{$log->slug}_has_fbclid"
                : "site_{$siteId}_has_fbclid";
            $signals[] = ['var' => $var, 'value' => '1'];
        } elseif ($log->requireFbclid) {
            $signals[] = ['var' => "site_{$siteId}_has_fbclid_arg", 'value' => '1'];
        } elseif ($log->requireSocialReferer) {
            $var = $log->overrideSocialRefererHosts !== null
                ? "site_{$siteId}_log_{$log->slug}_has_social_referer"
                : "site_{$siteId}_has_social_referer";
            $signals[] = ['var' => $var, 'value' => '1'];
        }

        if ($log->requireTargetCountry) {
            $var = $log->overrideCountries !== null
                ? "site_{$siteId}_log_{$log->slug}_is_target_country"
                : "site_{$siteId}_is_target_country";
            $signals[] = ['var' => $var, 'value' => '1'];
        }

        if ($log->requireTargetPage) {
            $var = $log->overridePages !== null
                ? "site_{$siteId}_log_{$log->slug}_is_target_page"
                : "site_{$siteId}_is_target_page";
            $signals[] = ['var' => $var, 'value' => '1'];
        }

        return $signals;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [httpParts, serverParts]
     */
    private function renderCustomLog(ForgeSiteCustomLog $log, string $siteId, string $logFormatName): array
    {
        $http = [];
        $server = [];
        $hitVar = "site_{$siteId}_log_{$log->slug}_hit";

        // Per-log override maps.
        if ($log->requireTargetCountry && $log->overrideCountries !== null) {
            $perLogVar = "site_{$siteId}_log_{$log->slug}_is_target_country";
            $http[] = "# --- \${$perLogVar} (per-log override) ---";
            $http[] = "map \$http_cf_ipcountry \${$perLogVar} {";
            $http[] = '    default 0;';

            foreach ($log->overrideCountries as $code) {
                $http[] = sprintf('    "%s" 1;', $code);
            }

            $http[] = '}';
            $http[] = '';
        }

        if ($log->requireTargetPage && $log->overridePages !== null) {
            $perLogVar = "site_{$siteId}_log_{$log->slug}_is_target_page";
            $http[] = "# --- \${$perLogVar} (per-log override) ---";
            $http[] = "map \$request_uri \${$perLogVar} {";
            $http[] = '    default 0;';

            foreach ($log->overridePages as $body) {
                $http[] = sprintf('    "~*%s" 1;', $body);
            }

            $http[] = '}';
            $http[] = '';
        }

        if ($log->requireSocialReferer && $log->overrideSocialRefererHosts !== null) {
            $perLogVar = "site_{$siteId}_log_{$log->slug}_has_social_referer";
            $http[] = "# --- \${$perLogVar} (per-log override) ---";
            $http[] = "map \$http_referer \${$perLogVar} {";
            $http[] = '    default 0;';

            foreach ($log->overrideSocialRefererHosts as $host) {
                $http[] = sprintf(
                    '    "~*^https?://([^/]*\.)?%s(/|$)" 1;',
                    preg_quote($host, '/'),
                );
            }

            $http[] = '}';
            $http[] = '';

            // Composite + override: rebuild the OR map against the per-log referer var.
            if ($log->requireFbclid) {
                $hasFbclidArgVar = "site_{$siteId}_has_fbclid_arg";
                $perLogComposite = "site_{$siteId}_log_{$log->slug}_has_fbclid";
                $http[] = "# --- \${$perLogComposite} = fbclid OR per-log social referer ---";
                $http[] = sprintf(
                    'map "$%s$%s" $%s {',
                    $hasFbclidArgVar,
                    $perLogVar,
                    $perLogComposite,
                );
                $http[] = '    default 1;';
                $http[] = '    "00"    0;';
                $http[] = '}';
                $http[] = '';
            }
        }

        // Hit map.
        $signals = $this->customLogResolvedSignals($log, $siteId);

        if ($signals === []) {
            // Always-on log: matches every request. Use $request_id as a non-empty source.
            $http[] = "# --- \${$hitVar} (always on) ---";
            $http[] = "map \$request_id \${$hitVar} {";
            $http[] = '    default 1;';
            $http[] = '}';
            $http[] = '';
        } elseif (count($signals) === 1) {
            $signal = $signals[0];
            $http[] = "# --- \${$hitVar} (single signal) ---";
            $http[] = sprintf('map $%s $%s {', $signal['var'], $hitVar);
            $http[] = '    default 0;';
            $http[] = sprintf('    "%s"     1;', $signal['value']);
            $http[] = '}';
            $http[] = '';
        } else {
            $keyVars = implode(':', array_map(
                fn (array $s): string => '$'.$s['var'],
                $signals,
            ));
            $keyValues = implode(':', array_map(fn (array $s): string => $s['value'], $signals));

            $http[] = "# --- \${$hitVar} (composite) ---";
            $http[] = sprintf('map "%s" $%s {', $keyVars, $hitVar);
            $http[] = '    default 0;';
            $http[] = sprintf('    "%s" 1;', $keyValues);
            $http[] = '}';
            $http[] = '';
        }

        // Per-log access_log line.
        $server[] = sprintf(
            'access_log %s %s if=$%s;',
            self::defaultSiteCustomLogPath($siteId, $log->slug),
            $logFormatName,
            $hitVar,
        );

        return [$http, $server];
    }

    public static function defaultSiteCustomLogPath(string $siteId, string $slug): string
    {
        return "/var/log/nginx/site-{$siteId}-{$slug}.log";
    }

    /**
     * @param  list<array{field: string, var: string, value: string, scope: string}>  $enabled
     * @return array{0: string, 1: string} [joined key vars, joined key values]
     */
    private function compositeKey(array $enabled, string $siteId): array
    {
        $keyVars = implode(':', array_map(
            fn (array $s): string => '$'.$this->resolveSignalVar($s, $siteId),
            $enabled,
        ));
        $keyValues = implode(':', array_map(fn (array $s): string => $s['value'], $enabled));

        return [$keyVars, $keyValues];
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

    private function assertSocialRefererHost(string $host): void
    {
        if ($host === '') {
            throw new InvalidArgumentException('Social referer host cannot be empty.');
        }

        if (preg_match('/[\s|()"\'~\\\\\r\n]/', $host) === 1) {
            throw new InvalidArgumentException("Social referer host contains forbidden character: {$host}");
        }
    }
}
