<?php

declare(strict_types=1);

namespace App\Services\Nginx\Antibot;

use App\Services\Nginx\Antibot\Dto\AntibotSettings;

class AntibotSettingsParser
{
    public function parse(string $content): AntibotSettings
    {
        return new AntibotSettings(
            botPatterns: $this->extractBotPatterns($content),
            targetCountries: $this->extractTargetCountries($content),
            targetPages: $this->extractTargetPages($content),
        );
    }

    /**
     * @return list<string>
     */
    private function extractBotPatterns(string $content): array
    {
        $body = $this->extractMapBody($content, '$http_user_agent', '$is_bot');

        if ($body === null || preg_match('/~\*\(([^)]*)\)\s+1\s*;/', $body, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode('|', $m[1])),
            fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function extractTargetCountries(string $content): array
    {
        $body = $this->extractMapBody($content, '$http_cf_ipcountry', '$is_target_country');

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
        $body = $this->extractMapBody($content, '$request_uri', '$is_target_page');

        if ($body === null || preg_match_all('/"~\*((?:\\\\.|[^"\\\\])*)"\s+1\s*;/', $body, $m) === false) {
            return [];
        }

        return $m[1] ?? [];
    }

    private function extractMapBody(string $content, string $source, string $destination): ?string
    {
        $pattern = sprintf(
            '/map\s+%s\s+%s\s*\{([^}]*)\}/',
            preg_quote($source, '/'),
            preg_quote($destination, '/'),
        );

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
