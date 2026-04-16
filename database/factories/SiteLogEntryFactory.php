<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\SiteLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SiteLogEntry>
 */
class SiteLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = fake()->dateTimeBetween('-1 day', 'now');

        return [
            'server_id' => Server::factory(),
            'site_id' => (string) fake()->numberBetween(3000000, 3999999),
            'request_id' => Str::random(32),
            'occurred_at' => $occurredAt,
            'gated' => false,
            'host' => fake()->domainName(),
            'remote_addr' => fake()->ipv4(),
            'uri' => '/'.fake()->slug(),
            'request_uri' => '/'.fake()->slug(),
            'fbclid' => null,
            'user_agent' => fake()->userAgent(),
            'iso_country' => fake()->countryCode(),
            'prefetch' => 'document',
            'turbolink' => null,
            'sec_ch_ua' => '"Chromium";v="147"',
            'sec_ch_ua_platform' => '"macOS"',
            'sec_ch_ua_mobile' => '?0',
            'raw_line' => '[stub]',
            'imported_at' => now(),
        ];
    }

    public function today(): static
    {
        return $this->state(['occurred_at' => now()->setTime(fake()->numberBetween(0, 23), fake()->numberBetween(0, 59))]);
    }

    public function gated(): static
    {
        return $this->state(['gated' => true]);
    }

    public function withFbclid(): static
    {
        return $this->state(['fbclid' => Str::random(16)]);
    }
}
