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
