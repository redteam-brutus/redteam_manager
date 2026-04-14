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

        foreach ($settings->targetCountries as $code) {
            $this->assertCountryCode($code);
        }

        foreach ($settings->targetPages as $body) {
            $this->assertTargetPage($body);
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

        $parts[] = '# --- Target country detection ($is_target_country) ---';
        $parts[] = 'map $http_cf_ipcountry $is_target_country {';
        $parts[] = '    default 0;';

        foreach ($settings->targetCountries as $code) {
            $parts[] = sprintf('    "%s" 1;', $code);
        }

        $parts[] = '}';
        $parts[] = '';

        $parts[] = '# --- Target page detection ($is_target_page) ---';
        $parts[] = 'map $request_uri $is_target_page {';
        $parts[] = '    default 0;';

        foreach ($settings->targetPages as $body) {
            $parts[] = sprintf('    "~*%s" 1;', $body);
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
