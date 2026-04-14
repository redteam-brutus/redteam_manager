<?php

declare(strict_types=1);

namespace App\Services\Nginx\Antibot;

use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use InvalidArgumentException;

class AntibotSettingsRenderer
{
    public function render(AntibotSettings $settings): string
    {
        if ($settings->isEmpty()) {
            return '';
        }

        foreach ($settings->botPatterns as $pattern) {
            $this->assertBotPattern($pattern);
        }

        $parts = ['# Managed by redteam-manager. Do not edit by hand.', ''];

        $parts[] = '# --- Bot UA detection ($is_bot) ---';
        $parts[] = 'map $http_user_agent $is_bot {';
        $parts[] = '    default 0;';

        if ($settings->botPatterns !== []) {
            $parts[] = '    ~*('.implode('|', $settings->botPatterns).') 1;';
        }

        $parts[] = '}';
        $parts[] = '';

        return implode("\n", $parts);
    }

    private function assertBotPattern(string $pattern): void
    {
        if ($pattern === '') {
            throw new InvalidArgumentException('Bot pattern cannot be empty.');
        }

        if (preg_match('/^(?:\\\\.|[^|()\n\r])+$/D', $pattern) !== 1) {
            throw new InvalidArgumentException("Bot pattern contains forbidden chars (|, unescaped parens, or newline): {$pattern}");
        }
    }
}
