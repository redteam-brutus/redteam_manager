<?php

declare(strict_types=1);

namespace App\Services\SiteLogs;

use Carbon\CarbonImmutable;
use Throwable;

class VerboseLogLineParser
{
    /**
     * Pattern mirrors ForgeSiteSettingsRenderer::VERBOSE_LOG_FORMAT. Unmatched lines return null
     * so the ingester can count skips instead of blowing up the batch.
     */
    private const LINE_PATTERN = '/^\[(?<ts>[^\]]+)\]\s+Host:\s+(?<host>\S+)\s+\|\s+IP:\s+(?<ip>\S+)\s+\|\s+ReqID:\s+(?<req>\S+)\s+\|\s+Path:\s+(?<path>\S+)\s+\|\s+Request URI:\s+(?<uri>\S+)\s+\|\s+FBCLID:\s*(?<fbclid>[^|]*?)\s*\|\s+UA:\s+"(?<ua>.*)"\s+\|\s+ISO:\s+"(?<iso>[^"]*)"\s+\|\s+Prefetch:\s+\[(?<pre>[^\]]*)\]\s+\|\s+Turbolink:\s+\[(?<turbo>[^\]]*)\]\s+\|\s+client hints:\s+\[(?<ch>.*)\]\s+-\s+\[(?<chp>[^\]]*)\]\s+-\s+\[(?<chm>[^\]]*)\]\s*$/';

    /**
     * @return array<string, mixed>|null matches the columns of site_log_entries, sans id/server/gated.
     */
    public function parse(string $line): ?array
    {
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === '') {
            return null;
        }

        if (preg_match(self::LINE_PATTERN, $trimmed, $m) !== 1) {
            return null;
        }

        try {
            $occurredAt = CarbonImmutable::createFromFormat('d/M/Y:H:i:s O', $m['ts']);
        } catch (Throwable) {
            return null;
        }

        if ($occurredAt === false) {
            return null;
        }

        return [
            'occurred_at' => $occurredAt,
            'request_id' => $m['req'],
            'host' => self::nullIfDash($m['host']),
            'remote_addr' => self::nullIfDash($m['ip']),
            'uri' => self::nullIfDash($m['path']),
            'request_uri' => self::nullIfDash($m['uri']),
            'fbclid' => self::nullIfDash($m['fbclid']),
            'user_agent' => self::nullIfDash($m['ua']),
            'iso_country' => self::isoOrNull($m['iso']),
            'prefetch' => self::nullIfDash($m['pre']),
            'turbolink' => self::nullIfDash($m['turbo']),
            'sec_ch_ua' => self::nullIfDash($m['ch']),
            'sec_ch_ua_platform' => self::nullIfDash($m['chp']),
            'sec_ch_ua_mobile' => self::nullIfDash($m['chm']),
            'raw_line' => $trimmed,
        ];
    }

    private static function nullIfDash(string $value): ?string
    {
        $value = trim($value);

        return ($value === '' || $value === '-') ? null : $value;
    }

    private static function isoOrNull(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }
}
